<?php

declare(strict_types=1);

namespace TrangThu\Books;

use PDO;

final class ChapterSubmissionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function submit(int $ownerId, string $bookSlug, string $title, array $parts): ?array
    {
        $this->db->beginTransaction();
        try {
            $bookStatement = $this->db->prepare(
                "SELECT id, title FROM books WHERE slug = :slug AND owner_id = :owner_id "
                . "AND visibility = 'public' AND moderation_status = 'approved' LIMIT 1 FOR UPDATE"
            );
            $bookStatement->execute(['slug' => $bookSlug, 'owner_id' => $ownerId]);
            $book = $bookStatement->fetch();
            if (!is_array($book)) {
                $this->db->rollBack();
                return null;
            }

            $numberStatement = $this->db->prepare(
                'SELECT COALESCE(MAX(chapter_number), 0) + 1 FROM chapters WHERE book_id = :book_id'
            );
            $numberStatement->execute(['book_id' => $book['id']]);
            $chapterNumber = (int) $numberStatement->fetchColumn();
            $insert = $this->db->prepare(
                "INSERT INTO chapters (book_id, chapter_number, part_number, title, content, word_count, visibility, moderation_status) "
                . "VALUES (:book_id, :chapter_number, :part_number, :title, :content, :word_count, 'hidden', 'pending')"
            );
            foreach ($parts as $index => $part) {
                $partNumber = $index + 1;
                $insert->execute([
                    'book_id' => $book['id'],
                    'chapter_number' => $chapterNumber,
                    'part_number' => $partNumber,
                    'title' => $title,
                    'content' => $part['content'],
                    'word_count' => $part['word_count'],
                ]);
            }
            $this->db->commit();

            return [
                'book_title' => $book['title'],
                'chapter_number' => $chapterNumber,
                'parts_count' => count($parts),
                'status' => 'pending',
            ];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
