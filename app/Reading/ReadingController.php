<?php

declare(strict_types=1);

namespace TrangThu\Reading;

use TrangThu\Core\Auth;
use TrangThu\Core\Csrf;
use TrangThu\Core\Request;
use TrangThu\Core\Response;

final class ReadingController
{
    public function __construct(private readonly ReadingRepository $reading)
    {
    }

    public function history(): never
    {
        $user = Auth::requireUser();
        Response::json(['data' => $this->reading->history((int) $user['id'])]);
    }

    public function saveProgress(): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        $input = Request::json();
        $chapterId = $input['chapter_id'] ?? null;
        $progress = $input['progress_percent'] ?? null;

        if (!is_int($chapterId) || $chapterId < 1 || !is_int($progress) || $progress < 0 || $progress > 100) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Tiến độ đọc không hợp lệ.']], 422);
        }

        if (!$this->reading->saveProgress((int) $user['id'], $chapterId, $progress)) {
            Response::notFound();
        }

        Response::json(['data' => ['chapter_id' => $chapterId, 'progress_percent' => $progress]]);
    }
}
