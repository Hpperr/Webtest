<?php

declare(strict_types=1);

namespace TrangThu\Reading;

use PDO;

final class ReadingRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function history(int $userId): array
    {
        $statement = $this->db->prepare(
            "SELECT b.slug AS book_slug, b.title AS book_title, b.author_name AS author, "
            . "c.id AS chapter_id, c.chapter_number, c.title AS chapter_title, "
            . "rh.progress_percent, rh.last_read_at "
            . "FROM reading_history rh INNER JOIN books b ON b.id = rh.book_id "
            . "INNER JOIN chapters c ON c.id = rh.chapter_id "
            . "WHERE rh.user_id = :user_id AND b.visibility = 'public' "
            . "AND b.moderation_status = 'approved' AND c.visibility = 'public' AND c.moderation_status = 'approved' "
            . "ORDER BY rh.last_read_at DESC LIMIT 50"
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function saveProgress(int $userId, int $chapterId, int $progressPercent): bool
    {
        $chapter = $this->db->prepare(
            "SELECT c.book_id FROM chapters c INNER JOIN books b ON b.id = c.book_id "
            . "WHERE c.id = :chapter_id AND c.visibility = 'public' AND c.moderation_status = 'approved' "
            . "AND b.visibility = 'public' AND b.moderation_status = 'approved' LIMIT 1"
        );
        $chapter->execute(['chapter_id' => $chapterId]);
        $bookId = $chapter->fetchColumn();
        if ($bookId === false) {
            return false;
        }

        $statement = $this->db->prepare(
            'INSERT INTO reading_history (user_id, book_id, chapter_id, progress_percent, last_read_at) '
            . 'VALUES (:user_id, :book_id, :chapter_id, :progress_percent, UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE chapter_id = VALUES(chapter_id), '
            . 'progress_percent = VALUES(progress_percent), last_read_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'user_id' => $userId,
            'book_id' => (int) $bookId,
            'chapter_id' => $chapterId,
            'progress_percent' => $progressPercent,
        ]);

        return true;
    }
}
