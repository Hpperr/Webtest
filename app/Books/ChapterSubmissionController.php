<?php

declare(strict_types=1);

namespace TrangThu\Books;

use TrangThu\Core\Auth;
use TrangThu\Core\Config;
use TrangThu\Core\Csrf;
use TrangThu\Core\Request;
use TrangThu\Core\Response;
use TrangThu\Import\ChapterSplitter;

final class ChapterSubmissionController
{
    public function __construct(private readonly ChapterSubmissionRepository $chapters)
    {
    }

    public function submit(string $bookSlug): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        $input = Request::json(4_100_000);
        $title = $input['title'] ?? null;
        $content = $input['content'] ?? null;
        if (!is_string($title) || !is_string($content)) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Cần nhập tiêu đề và nội dung chương.']], 422);
        }
        $title = trim($title);
        $content = trim(str_replace(["\r\n", "\r"], "\n", $content));
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content) ?? '';
        if (mb_strlen($title) < 1 || mb_strlen($title) > 240 || $content === ''
            || strlen($content) > 4_000_000 || mb_strlen($content) > 1_000_000) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Tiêu đề hoặc nội dung chương vượt giới hạn cho phép.']], 422);
        }

        $wordLimit = (int) Config::get('IMPORT_CHAPTER_WORD_LIMIT', '7000');
        $parts = (new ChapterSplitter($wordLimit))->splitChapter($title, $content);
        if ($parts === []) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Chương cần có nội dung văn bản.']], 422);
        }
        $submitted = $this->chapters->submit((int) $user['id'], $bookSlug, $title, $parts);
        if ($submitted === null) {
            Response::json(['error' => ['code' => 'not_found', 'message' => 'Chỉ chủ sở hữu sách đã được duyệt mới có thể gửi chương.']], 404);
        }
        Response::json(['data' => $submitted], 202);
    }
}
