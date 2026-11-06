<?php

declare(strict_types=1);

namespace TrangThu\Books;

use TrangThu\Core\Request;
use TrangThu\Core\Response;
use TrangThu\Core\Auth;

final class BookController
{
    public function __construct(private readonly BookRepository $books)
    {
    }

    public function index(): never
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($query) > 120) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Từ khóa tìm kiếm quá dài.']], 422);
        }
        $filters = [
            'q' => $query,
            'genre' => $this->filterString($_GET['genre'] ?? '', 64),
            'status' => $this->filterString($_GET['status'] ?? '', 32),
            'sort' => $this->filterString($_GET['sort'] ?? 'updated', 16),
            'limit' => $this->boundedInt($_GET['limit'] ?? 20, 1, 50, 20),
            'offset' => $this->boundedInt($_GET['offset'] ?? 0, 0, 100000, 0),
        ];

        Response::json(['data' => $this->books->search($filters), 'meta' => [
            'limit' => $filters['limit'],
            'offset' => $filters['offset'],
            'filters' => ['q' => $query, 'genre' => $filters['genre'], 'status' => $filters['status'], 'sort' => $filters['sort']],
        ]]);
    }

    public function show(string $slug): never
    {
        $user = Auth::user();
        $book = $this->books->findPublicBySlug($slug, is_array($user) ? (int) $user['id'] : 0);
        if ($book === null) {
            Response::notFound();
        }

        $page = $this->boundedInt($_GET['page'] ?? 1, 1, 100000, 1);
        $pageSize = 20;
        $book['chapters'] = $this->books->chapters((int) $book['id'], $pageSize, ($page - 1) * $pageSize);
        $book['chapter_count'] = $this->books->countPublicChapters((int) $book['id']);
        Response::json(['data' => $book, 'meta' => ['chapter_page' => $page, 'chapter_page_size' => $pageSize]]);
    }

    public function chapter(int $id): never
    {
        Auth::requireUser();
        $chapter = $this->books->findPublicChapter($id);
        if ($chapter === null) {
            Response::notFound();
        }
        Response::json(['data' => $chapter]);
    }

    public function genres(): never
    {
        Response::json(['data' => $this->books->genres()]);
    }

    private function filterString(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, $maxLength);
    }

    private function boundedInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $fallback;
        }

        return min($max, max($min, (int) $value));
    }
}
