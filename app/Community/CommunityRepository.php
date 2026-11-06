<?php

declare(strict_types=1);

namespace TrangThu\Community;

use PDO;
use TrangThu\Notifications\NotificationRepository;

final class CommunityRepository
{
    private NotificationRepository $notifications;

    public function __construct(private readonly PDO $db)
    {
        $this->notifications = new NotificationRepository($db);
    }

    public function forBook(string $slug, int $userId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT b.id, "
            . "(SELECT COUNT(*) FROM reactions r WHERE r.book_id = b.id AND r.reaction = 'like') AS likes, "
            . "(SELECT COUNT(*) FROM reactions r WHERE r.book_id = b.id AND r.reaction = 'love') AS loves, "
            . "(SELECT COUNT(*) FROM follows f WHERE f.book_id = b.id) AS followers, "
            . "(SELECT r.reaction FROM reactions r WHERE r.book_id = b.id AND r.user_id = :reaction_user LIMIT 1) AS my_reaction, "
            . "EXISTS(SELECT 1 FROM follows f WHERE f.book_id = b.id AND f.user_id = :follow_user) AS is_following "
            . "FROM books b WHERE b.slug = :slug AND b.visibility = 'public' "
            . "AND b.moderation_status = 'approved' LIMIT 1"
        );
        $statement->execute(['reaction_user' => $userId, 'follow_user' => $userId, 'slug' => $slug]);
        $book = $statement->fetch();
        if (!is_array($book)) {
            return null;
        }

        return [
            'likes' => (int) $book['likes'],
            'loves' => (int) $book['loves'],
            'followers' => (int) $book['followers'],
            'my_reaction' => $book['my_reaction'],
            'is_following' => (bool) $book['is_following'],
            'comments' => $this->comments((int) $book['id']),
        ];
    }

    public function setFollow(int $userId, string $slug, bool $follow): bool
    {
        $this->db->beginTransaction();
        try {
            $book = $this->publicBook($slug);
            if ($book === null) {
                $this->db->rollBack();
                return false;
            }
            if ($follow) {
                $statement = $this->db->prepare('INSERT IGNORE INTO follows (user_id, book_id) VALUES (:user_id, :book_id)');
            } else {
                $statement = $this->db->prepare('DELETE FROM follows WHERE user_id = :user_id AND book_id = :book_id');
            }
            $statement->execute(['user_id' => $userId, 'book_id' => $book['id']]);
            if ($follow && $statement->rowCount() > 0) {
                $this->notifyOwner($book, $userId, 'new_follower', []);
            }
            $this->db->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function setReaction(int $userId, string $slug, ?string $reaction): bool
    {
        $this->db->beginTransaction();
        try {
            $book = $this->publicBook($slug);
            if ($book === null) {
                $this->db->rollBack();
                return false;
            }
            if ($reaction === null) {
                $statement = $this->db->prepare('DELETE FROM reactions WHERE user_id = :user_id AND book_id = :book_id');
                $statement->execute(['user_id' => $userId, 'book_id' => $book['id']]);
                $this->db->commit();
                return true;
            }

            $existing = $this->db->prepare('SELECT reaction FROM reactions WHERE user_id = :user_id AND book_id = :book_id');
            $existing->execute(['user_id' => $userId, 'book_id' => $book['id']]);
            $previous = $existing->fetchColumn();
            if ($previous === $reaction) {
                $this->db->commit();
                return true;
            }
            $statement = $this->db->prepare(
                'INSERT INTO reactions (user_id, book_id, reaction) VALUES (:user_id, :book_id, :reaction) '
                . 'ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), created_at = UTC_TIMESTAMP()'
            );
            $statement->execute(['user_id' => $userId, 'book_id' => $book['id'], 'reaction' => $reaction]);
            $this->notifyOwner($book, $userId, 'new_reaction', ['reaction' => $reaction]);
            $this->db->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function addComment(int $userId, string $slug, string $body): ?array
    {
        $this->db->beginTransaction();
        try {
            $book = $this->publicBook($slug);
            if ($book === null) {
                $this->db->rollBack();
                return null;
            }
            $statement = $this->db->prepare(
                "INSERT INTO comments (user_id, book_id, body, status) VALUES (:user_id, :book_id, :body, 'visible')"
            );
            $statement->execute(['user_id' => $userId, 'book_id' => $book['id'], 'body' => $body]);
            $commentId = (int) $this->db->lastInsertId();
            $this->notifyOwner($book, $userId, 'new_comment', []);
            $comment = $this->comment($commentId);
            $this->db->commit();
            return $comment;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function addReport(int $userId, string $slug, string $reason, string $details, ?int $chapterId = null): bool
    {
        $bookId = $this->publicBookId($slug);
        if ($bookId === null) {
            return false;
        }
        if ($chapterId !== null) {
            $chapter = $this->db->prepare(
                "SELECT id FROM chapters WHERE id = :chapter_id AND book_id = :book_id "
                . "AND visibility = 'public' AND moderation_status = 'approved' LIMIT 1"
            );
            $chapter->execute(['chapter_id' => $chapterId, 'book_id' => $bookId]);
            if ($chapter->fetchColumn() === false) {
                return false;
            }
        }
        $statement = $this->db->prepare(
            "INSERT INTO reports (reporter_id, book_id, chapter_id, reason, details, status) "
            . "VALUES (:reporter_id, :book_id, :chapter_id, :reason, :details, 'open')"
        );
        $statement->execute([
            'reporter_id' => $userId,
            'book_id' => $bookId,
            'chapter_id' => $chapterId,
            'reason' => $reason,
            'details' => $details === '' ? null : $details,
        ]);

        return true;
    }

    private function comments(int $bookId): array
    {
        $statement = $this->db->prepare(
            "SELECT c.id, c.body, c.created_at, u.display_name AS author "
            . "FROM comments c INNER JOIN users u ON u.id = c.user_id "
            . "WHERE c.book_id = :book_id AND c.status = 'visible' AND u.status = 'active' "
            . "ORDER BY c.created_at DESC, c.id DESC LIMIT 50"
        );
        $statement->execute(['book_id' => $bookId]);

        return $statement->fetchAll();
    }

    private function comment(int $id): ?array
    {
        $statement = $this->db->prepare(
            "SELECT c.id, c.body, c.created_at, u.display_name AS author "
            . "FROM comments c INNER JOIN users u ON u.id = c.user_id "
            . "WHERE c.id = :id AND c.status = 'visible' LIMIT 1"
        );
        $statement->execute(['id' => $id]);
        $comment = $statement->fetch();

        return is_array($comment) ? $comment : null;
    }

    private function publicBook(string $slug): ?array
    {
        $statement = $this->db->prepare(
            "SELECT b.id, b.owner_id, b.title, b.slug, u.display_name AS owner_name, u.status AS owner_status "
            . "FROM books b LEFT JOIN users u ON u.id = b.owner_id "
            . "WHERE b.slug = :slug AND b.visibility = 'public' AND b.moderation_status = 'approved' LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['slug' => $slug]);
        $book = $statement->fetch();

        if (!is_array($book)) {
            return null;
        }
        $book['id'] = (int) $book['id'];
        $book['owner_id'] = $book['owner_id'] === null ? null : (int) $book['owner_id'];
        return $book;
    }

    private function publicBookId(string $slug): ?int
    {
        $statement = $this->db->prepare(
            "SELECT id FROM books WHERE slug = :slug AND visibility = 'public' "
            . "AND moderation_status = 'approved' LIMIT 1"
        );
        $statement->execute(['slug' => $slug]);
        $bookId = $statement->fetchColumn();

        return $bookId === false ? null : (int) $bookId;
    }

    private function notifyOwner(array $book, int $actorId, string $type, array $extra): void
    {
        if ($book['owner_id'] === null || $book['owner_id'] === $actorId || $book['owner_status'] !== 'active') {
            return;
        }
        $actor = $this->db->prepare('SELECT display_name FROM users WHERE id = :id');
        $actor->execute(['id' => $actorId]);
        $actorName = $actor->fetchColumn();
        if (!is_string($actorName)) {
            return;
        }
        $this->notifications->create(
            $book['owner_id'],
            $type,
            $extra + ['actor' => $actorName, 'book_title' => $book['title'], 'book_slug' => $book['slug']]
        );
    }
}
