<?php

declare(strict_types=1);

namespace TrangThu\Import;

use PDO;

final class ImportRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function createQueued(
        int $userId,
        string $genreSlug,
        string $title,
        string $author,
        string $description,
        string $sourcePath,
        string $sourceType
    ): ?array {
        $this->db->beginTransaction();
        try {
            $genre = $this->db->prepare('SELECT id FROM genres WHERE slug = :slug LIMIT 1');
            $genre->execute(['slug' => $genreSlug]);
            $genreId = $genre->fetchColumn();
            if ($genreId === false) {
                $this->db->rollBack();
                return null;
            }

            $slug = 'import-' . bin2hex(random_bytes(12));
            $book = $this->db->prepare(
                "INSERT INTO books (owner_id, genre_id, slug, title, author_name, description, status, visibility, moderation_status) "
                . "VALUES (:owner_id, :genre_id, :slug, :title, :author, :description, 'ongoing', 'private', 'pending')"
            );
            $book->execute([
                'owner_id' => $userId,
                'genre_id' => (int) $genreId,
                'slug' => $slug,
                'title' => $title,
                'author' => $author,
                'description' => $description,
            ]);
            $bookId = (int) $this->db->lastInsertId();

            $job = $this->db->prepare(
                "INSERT INTO import_jobs (user_id, book_id, source_path, source_type, status) "
                . "VALUES (:user_id, :book_id, :source_path, :source_type, 'queued')"
            );
            $job->execute([
                'user_id' => $userId,
                'book_id' => $bookId,
                'source_path' => $sourcePath,
                'source_type' => $sourceType,
            ]);
            $jobId = (int) $this->db->lastInsertId();
            $this->db->commit();

            return ['job_id' => $jobId, 'book_id' => $bookId, 'book_slug' => $slug];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function forUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT ij.id, ij.source_type, ij.status, ij.error_message, ij.created_at, '
            . 'b.title AS book_title, b.slug AS book_slug '
            . 'FROM import_jobs ij LEFT JOIN books b ON b.id = ij.book_id '
            . 'WHERE ij.user_id = :user_id ORDER BY ij.created_at DESC LIMIT 50'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function claimNext(): ?array
    {
        $this->db->beginTransaction();
        try {
            $job = $this->db->query(
                "SELECT id, book_id, source_path, source_type FROM import_jobs "
                . "WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1 FOR UPDATE"
            )->fetch();
            if (!is_array($job)) {
                $this->db->commit();
                return null;
            }
            $statement = $this->db->prepare("UPDATE import_jobs SET status = 'processing' WHERE id = :id");
            $statement->execute(['id' => $job['id']]);
            $this->db->commit();

            return $job;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function complete(int $jobId, int $bookId, array $chapters): void
    {
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare('DELETE FROM chapters WHERE book_id = :book_id');
            $delete->execute(['book_id' => $bookId]);
            $insert = $this->db->prepare(
                'INSERT INTO chapters (book_id, chapter_number, part_number, title, content, word_count, visibility, published_at) '
                . "VALUES (:book_id, :chapter_number, :part_number, :title, :content, :word_count, 'public', UTC_TIMESTAMP())"
            );
            foreach ($chapters as $chapter) {
                $insert->execute([
                    'book_id' => $bookId,
                    'chapter_number' => $chapter['chapter_number'],
                    'part_number' => $chapter['part_number'],
                    'title' => $chapter['title'],
                    'content' => $chapter['content'],
                    'word_count' => $chapter['word_count'],
                ]);
            }
            $job = $this->db->prepare("UPDATE import_jobs SET status = 'review', error_message = NULL WHERE id = :id");
            $job->execute(['id' => $jobId]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function fail(int $jobId, string $message): void
    {
        $statement = $this->db->prepare(
            "UPDATE import_jobs SET status = 'failed', error_message = :message WHERE id = :id"
        );
        $statement->execute(['message' => mb_substr($message, 0, 500), 'id' => $jobId]);
    }
}
