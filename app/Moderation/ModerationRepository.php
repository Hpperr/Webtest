<?php

declare(strict_types=1);

namespace TrangThu\Moderation;

use PDO;
use TrangThu\Notifications\NotificationRepository;

final class ModerationRepository
{
    private NotificationRepository $notifications;

    public function __construct(private readonly PDO $db)
    {
        $this->notifications = new NotificationRepository($db);
    }

    public function overview(): array
    {
        return [
            'pending_books' => (int) $this->db->query("SELECT COUNT(*) FROM books WHERE moderation_status = 'pending'")->fetchColumn(),
            'pending_chapters' => (int) $this->db->query("SELECT COUNT(*) FROM chapters WHERE moderation_status = 'pending'")->fetchColumn(),
            'open_reports' => (int) $this->db->query("SELECT COUNT(*) FROM reports WHERE status IN ('open', 'reviewing')")->fetchColumn(),
            'visible_comments' => (int) $this->db->query("SELECT COUNT(*) FROM comments WHERE status = 'visible'")->fetchColumn(),
            'active_users' => (int) $this->db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn(),
        ];
    }

    public function books(string $status): array
    {
        $statusCondition = $status === 'all'
            ? '1 = 1'
            : 'b.moderation_status = :status';
        $statement = $this->db->prepare(
            'SELECT b.id, b.title, b.author_name AS author, b.description, b.moderation_status AS status, '
            . 'b.visibility, b.created_at, g.name AS genre, u.display_name AS uploader '
            . 'FROM books b INNER JOIN genres g ON g.id = b.genre_id '
            . 'LEFT JOIN users u ON u.id = b.owner_id WHERE ' . $statusCondition . ' '
            . 'ORDER BY b.created_at ASC LIMIT 100'
        );
        $statement->execute($status === 'all' ? [] : ['status' => $status]);
        return $statement->fetchAll();
    }

    public function reports(string $status): array
    {
        $statusCondition = $status === 'active'
            ? "r.status IN ('open', 'reviewing')"
            : 'r.status = :status';
        $statement = $this->db->prepare(
            'SELECT r.id, r.reason, r.details, r.status, r.created_at, u.display_name AS reporter, '
            . 'b.title AS book_title, r.chapter_id, c.title AS chapter_title, '
            . 'c.chapter_number, c.part_number '
            . 'FROM reports r INNER JOIN users u ON u.id = r.reporter_id '
            . 'LEFT JOIN books b ON b.id = r.book_id LEFT JOIN chapters c ON c.id = r.chapter_id WHERE '
            . $statusCondition . ' '
            . 'ORDER BY r.created_at ASC LIMIT 100'
        );
        $statement->execute($status === 'active' ? [] : ['status' => $status]);
        return $statement->fetchAll();
    }

    public function chapters(string $status): array
    {
        $statusCondition = $status === 'all' ? '1 = 1' : 'c.moderation_status = :status';
        $statement = $this->db->prepare(
            'SELECT c.id, c.chapter_number, c.part_number, c.title, LEFT(c.content, 500) AS preview, c.word_count, '
            . 'c.moderation_status AS status, c.visibility, c.created_at, b.title AS book_title, b.slug AS book_slug, '
            . 'u.display_name AS author FROM chapters c INNER JOIN books b ON b.id = c.book_id '
            . 'LEFT JOIN users u ON u.id = b.owner_id WHERE ' . $statusCondition . ' '
            . 'ORDER BY c.created_at ASC LIMIT 100'
        );
        $statement->execute($status === 'all' ? [] : ['status' => $status]);
        return $statement->fetchAll();
    }

    public function comments(string $status): array
    {
        $statusCondition = $status === 'all'
            ? "c.status IN ('visible', 'hidden')"
            : 'c.status = :status';
        $statement = $this->db->prepare(
            'SELECT c.id, c.body, c.status, c.created_at, u.display_name AS author, b.title AS book_title '
            . 'FROM comments c INNER JOIN users u ON u.id = c.user_id '
            . 'INNER JOIN books b ON b.id = c.book_id WHERE ' . $statusCondition . ' '
            . 'ORDER BY c.created_at ASC LIMIT 100'
        );
        $statement->execute($status === 'all' ? [] : ['status' => $status]);
        return $statement->fetchAll();
    }

    public function users(): array
    {
        return $this->db->query(
            'SELECT id, display_name AS name, email, role, status, created_at FROM users ORDER BY created_at DESC LIMIT 100'
        )->fetchAll();
    }

    public function updateBook(int $adminId, int $id, string $action): bool
    {
        $changes = [
            'approve' => ['approved', 'public'],
            'reject' => ['rejected', 'hidden'],
            'hide' => ['approved', 'hidden'],
            'publish' => ['approved', 'public'],
        ];
        [$moderationStatus, $visibility] = $changes[$action];
        return $this->auditedUpdate(
            $adminId,
            'book',
            $id,
            $action,
            'UPDATE books SET moderation_status = :moderation_status, visibility = :visibility WHERE id = :id',
            ['moderation_status' => $moderationStatus, 'visibility' => $visibility, 'id' => $id]
        );
    }

    public function updateChapter(int $adminId, int $id, string $action): bool
    {
        if (!in_array($action, ['approve', 'reject', 'hide', 'restore'], true)) {
            return false;
        }
        $updates = [
            'approve' => "UPDATE chapters SET moderation_status = 'approved', visibility = 'public', "
                . 'published_at = COALESCE(published_at, CURRENT_TIMESTAMP) WHERE id = :id',
            'reject' => "UPDATE chapters SET moderation_status = 'rejected', visibility = 'hidden', "
                . 'published_at = NULL WHERE id = :id',
            'hide' => "UPDATE chapters SET visibility = 'hidden' WHERE id = :id",
            'restore' => "UPDATE chapters SET visibility = 'public' WHERE id = :id",
        ];
        return $this->auditedUpdate(
            $adminId,
            'chapter',
            $id,
            $action,
            $updates[$action],
            ['id' => $id],
            ['status' => $action]
        );
    }

    public function updateReport(int $adminId, int $id, string $status): bool
    {
        return $this->auditedUpdate(
            $adminId,
            'report',
            $id,
            $status,
            'UPDATE reports SET status = :status WHERE id = :id',
            ['status' => $status, 'id' => $id]
        );
    }

    public function updateComment(int $adminId, int $id, string $status): bool
    {
        return $this->auditedUpdate(
            $adminId,
            'comment',
            $id,
            $status,
            'UPDATE comments SET status = :status WHERE id = :id',
            ['status' => $status, 'id' => $id]
        );
    }

    public function updateUser(int $adminId, int $id, string $role, string $status): bool
    {
        return $this->auditedUpdate(
            $adminId,
            'user',
            $id,
            'update_access',
            'UPDATE users SET role = :role, status = :status WHERE id = :id',
            ['role' => $role, 'status' => $status, 'id' => $id],
            ['role' => $role, 'status' => $status]
        );
    }

    private function auditedUpdate(
        int $adminId,
        string $targetType,
        int $targetId,
        string $action,
        string $sql,
        array $parameters,
        array $details = []
    ): bool {
        $this->db->beginTransaction();
        try {
            $select = match ($targetType) {
                'book' => 'SELECT id, owner_id, title, slug, visibility FROM books WHERE id = :id LIMIT 1 FOR UPDATE',
                'chapter' => 'SELECT c.id, c.moderation_status, c.visibility, c.title, c.chapter_number, c.part_number, '
                    . 'b.id AS book_id, b.owner_id, b.title AS book_title, b.slug AS book_slug '
                    . 'FROM chapters c INNER JOIN books b ON b.id = c.book_id WHERE c.id = :id LIMIT 1 FOR UPDATE',
                default => 'SELECT id FROM ' . $this->tableFor($targetType) . ' WHERE id = :id LIMIT 1 FOR UPDATE',
            };
            $exists = $this->db->prepare($select);
            $exists->execute(['id' => $targetId]);
            $record = $exists->fetch();
            if (!is_array($record)) {
                $this->db->rollBack();
                return false;
            }
            if ($targetType === 'chapter') {
                $validTransition = match ($action) {
                    'approve', 'reject' => $record['moderation_status'] === 'pending',
                    'hide' => $record['moderation_status'] === 'approved' && $record['visibility'] === 'public',
                    'restore' => $record['moderation_status'] === 'approved' && $record['visibility'] === 'hidden',
                    default => false,
                };
                if (!$validTransition) {
                    $this->db->rollBack();
                    return false;
                }
            }

            $this->db->prepare($sql)->execute($parameters);
            if ($targetType === 'chapter' && $action === 'approve') {
                $this->db->prepare(
                    'UPDATE books SET updated_at = CURRENT_TIMESTAMP WHERE id = :book_id'
                )->execute(['book_id' => $record['book_id']]);
            }
            if ($targetType === 'book' && in_array($action, ['approve', 'publish'], true)
                && $record['visibility'] !== 'public') {
                $this->notifications->createForFollowers(
                    $targetId,
                    $record['owner_id'] === null ? 0 : (int) $record['owner_id'],
                    'book_available',
                    ['book_title' => $record['title'], 'book_slug' => $record['slug']]
                );
            }
            if ($targetType === 'chapter' && $action === 'approve'
                && $record['moderation_status'] === 'pending') {
                $this->notifications->createForFollowers(
                    (int) $record['book_id'],
                    $record['owner_id'] === null ? 0 : (int) $record['owner_id'],
                    'new_chapter',
                    [
                        'book_title' => $record['book_title'],
                        'book_slug' => $record['book_slug'],
                        'chapter_title' => $record['title'],
                        'chapter_number' => (int) $record['chapter_number'],
                        'part_number' => (int) $record['part_number'],
                        'chapter_id' => $targetId,
                    ]
                );
            }
            $audit = $this->db->prepare(
                'INSERT INTO moderation_actions (admin_id, target_type, target_id, action, details) '
                . 'VALUES (:admin_id, :target_type, :target_id, :action, :details)'
            );
            $audit->execute([
                'admin_id' => $adminId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'action' => $action,
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $this->db->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function tableFor(string $targetType): string
    {
        return [
            'book' => 'books',
            'chapter' => 'chapters',
            'report' => 'reports',
            'comment' => 'comments',
            'user' => 'users',
        ][$targetType];
    }
}
