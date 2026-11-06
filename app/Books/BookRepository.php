<?php

declare(strict_types=1);

namespace TrangThu\Books;

use PDO;

final class BookRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function search(array $filters): array
    {
        $conditions = ["b.visibility = 'public'", "b.moderation_status = 'approved'"];
        $parameters = [];
        foreach (['genre' => 'g.slug', 'status' => 'b.status'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '') {
                $conditions[] = $column . ' = :' . $filter;
                $parameters[$filter] = $filters[$filter];
            }
        }

        if (($filters['q'] ?? '') !== '') {
            $conditions[] = '(b.title LIKE :title_query OR b.author_name LIKE :author_query OR b.description LIKE :description_query)';
            $parameters['title_query'] = '%' . $filters['q'] . '%';
            $parameters['author_query'] = '%' . $filters['q'] . '%';
            $parameters['description_query'] = '%' . $filters['q'] . '%';
        }

        $sorts = [
            'updated' => 'b.updated_at DESC, b.id DESC',
            'title' => 'b.title ASC, b.id ASC',
            'popular' => 'b.read_count DESC, b.id DESC',
        ];
        $sort = $sorts[$filters['sort'] ?? 'updated'] ?? $sorts['updated'];
        $sql = 'SELECT b.id, b.slug, b.title, b.author_name AS author, b.description, b.cover_path AS cover, '
            . 'b.status, b.read_count, b.updated_at, g.name AS genre, g.slug AS genre_slug '
            . 'FROM books b INNER JOIN genres g ON g.id = b.genre_id WHERE '
            . implode(' AND ', $conditions) . ' ORDER BY ' . $sort . ' LIMIT :limit OFFSET :offset';
        $statement = $this->db->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
        $statement->bindValue(':offset', $filters['offset'], PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function findPublicBySlug(string $slug, int $viewerId = 0): ?array
    {
        $statement = $this->db->prepare(
            "SELECT b.id, b.slug, b.title, b.author_name AS author, b.description, b.cover_path AS cover, "
            . "b.status, b.read_count, b.updated_at, g.name AS genre, g.slug AS genre_slug, "
            . "IF(b.owner_id = :viewer_id, 1, 0) AS can_submit_chapter "
            . "FROM books b INNER JOIN genres g ON g.id = b.genre_id "
            . "WHERE b.slug = :slug AND b.visibility = 'public' AND b.moderation_status = 'approved' LIMIT 1"
        );
        $statement->execute(['viewer_id' => $viewerId, 'slug' => $slug]);
        $book = $statement->fetch();
        return $book === false ? null : $book;
    }

    public function chapters(int $bookId, int $limit = 50, int $offset = 0): array
    {
        $statement = $this->db->prepare(
            'SELECT id, chapter_number, part_number, title, word_count, published_at FROM chapters '
            . "WHERE book_id = :book_id AND visibility = 'public' AND moderation_status = 'approved' "
            . 'ORDER BY chapter_number ASC, part_number ASC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':book_id', $bookId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function countPublicChapters(int $bookId): int
    {
        $statement = $this->db->prepare(
            "SELECT COUNT(*) FROM chapters WHERE book_id = :book_id AND visibility = 'public' "
            . "AND moderation_status = 'approved'"
        );
        $statement->execute(['book_id' => $bookId]);
        return (int) $statement->fetchColumn();
    }

    public function findPublicChapter(int $chapterId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT c.id, c.book_id, c.chapter_number, c.part_number, c.title, c.content, c.word_count, c.published_at, "
            . "b.slug AS book_slug, b.title AS book_title, b.author_name AS author "
            . "FROM chapters c INNER JOIN books b ON b.id = c.book_id "
            . "WHERE c.id = :id AND c.visibility = 'public' AND c.moderation_status = 'approved' AND b.visibility = 'public' "
            . "AND b.moderation_status = 'approved' LIMIT 1"
        );
        $statement->execute(['id' => $chapterId]);
        $chapter = $statement->fetch();
        if (is_array($chapter)) {
            $view = $this->db->prepare(
                'UPDATE books SET read_count = read_count + 1, updated_at = updated_at WHERE id = :book_id'
            );
            $view->execute(['book_id' => $chapter['book_id']]);
        }
        return $chapter === false ? null : $chapter;
    }

    public function genres(): array
    {
        return $this->db->query('SELECT slug, name FROM genres ORDER BY name')->fetchAll();
    }
}
