<?php

declare(strict_types=1);

namespace TrangThu\Library;

use PDO;

final class LibraryRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function items(int $userId): array
    {
        $statement = $this->db->prepare(
            "SELECT b.id, b.slug, b.title, b.author_name AS author, b.description, "
            . "b.cover_path AS cover, b.status, b.read_count, b.updated_at, "
            . "g.name AS genre, g.slug AS genre_slug, li.created_at AS saved_at "
            . "FROM library_items li INNER JOIN books b ON b.id = li.book_id "
            . "INNER JOIN genres g ON g.id = b.genre_id "
            . "WHERE li.user_id = :user_id AND b.visibility = 'public' "
            . "AND b.moderation_status = 'approved' ORDER BY li.created_at DESC LIMIT 100"
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function isSaved(int $userId, string $slug): ?bool
    {
        $statement = $this->db->prepare(
            "SELECT EXISTS(SELECT 1 FROM books b WHERE b.slug = :slug "
            . "AND b.visibility = 'public' AND b.moderation_status = 'approved') AS book_exists, "
            . "EXISTS(SELECT 1 FROM library_items li INNER JOIN books b ON b.id = li.book_id "
            . "WHERE li.user_id = :user_id AND b.slug = :saved_slug) AS is_saved"
        );
        $statement->execute(['slug' => $slug, 'user_id' => $userId, 'saved_slug' => $slug]);
        $result = $statement->fetch();
        if (!is_array($result) || !(bool) $result['book_exists']) {
            return null;
        }

        return (bool) $result['is_saved'];
    }

    public function save(int $userId, string $slug): bool
    {
        $statement = $this->db->prepare(
            "INSERT IGNORE INTO library_items (user_id, book_id) "
            . "SELECT :user_id, b.id FROM books b WHERE b.slug = :slug "
            . "AND b.visibility = 'public' AND b.moderation_status = 'approved'"
        );
        $statement->execute(['user_id' => $userId, 'slug' => $slug]);

        return $this->isSaved($userId, $slug) === true;
    }

    public function remove(int $userId, string $slug): ?bool
    {
        $saved = $this->isSaved($userId, $slug);
        if ($saved === null) {
            return null;
        }

        $statement = $this->db->prepare(
            'DELETE li FROM library_items li INNER JOIN books b ON b.id = li.book_id '
            . 'WHERE li.user_id = :user_id AND b.slug = :slug'
        );
        $statement->execute(['user_id' => $userId, 'slug' => $slug]);

        return false;
    }
}
