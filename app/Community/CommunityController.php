<?php

declare(strict_types=1);

namespace TrangThu\Community;

use TrangThu\Core\Auth;
use TrangThu\Core\Csrf;
use TrangThu\Core\Request;
use TrangThu\Core\Response;

final class CommunityController
{
    private const REPORT_REASONS = ['copyright', 'spam', 'abuse', 'other'];

    public function __construct(private readonly CommunityRepository $community)
    {
    }

    public function show(string $slug): never
    {
        $user = Auth::user();
        $data = $this->community->forBook($slug, is_array($user) ? (int) $user['id'] : 0);
        if ($data === null) {
            Response::notFound();
        }
        Response::json(['data' => $data]);
    }

    public function follow(string $slug, bool $follow): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        if (!$this->community->setFollow((int) $user['id'], $slug, $follow)) {
            Response::notFound();
        }
        Response::json(['data' => ['following' => $follow]]);
    }

    public function react(string $slug): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        $input = Request::json();
        $reaction = $input['reaction'] ?? null;
        if (!is_string($reaction) || !in_array($reaction, ['like', 'love'], true)) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Cảm xúc không hợp lệ.']], 422);
        }
        if (!$this->community->setReaction((int) $user['id'], $slug, $reaction)) {
            Response::notFound();
        }
        Response::json(['data' => ['reaction' => $reaction]]);
    }

    public function removeReaction(string $slug): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        if (!$this->community->setReaction((int) $user['id'], $slug, null)) {
            Response::notFound();
        }
        Response::json(['data' => ['reaction' => null]]);
    }

    public function comment(string $slug): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        $input = Request::json();
        $body = $input['body'] ?? null;
        if (!is_string($body)) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Bình luận cần là văn bản.']], 422);
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 1000) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Bình luận cần có từ 1 đến 1.000 ký tự.']], 422);
        }
        $comment = $this->community->addComment((int) $user['id'], $slug, $body);
        if ($comment === null) {
            Response::notFound();
        }
        Response::json(['data' => $comment], 201);
    }

    public function report(string $slug): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        $input = Request::json();
        $reason = $input['reason'] ?? null;
        $details = $input['details'] ?? '';
        $chapterId = $input['chapter_id'] ?? null;
        if (!is_string($reason) || !in_array($reason, self::REPORT_REASONS, true)
            || !is_string($details) || mb_strlen($details) > 1000
            || ($chapterId !== null && (!is_int($chapterId) || $chapterId < 1))) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Thông tin báo cáo không hợp lệ.']], 422);
        }
        $details = trim($details);
        if (!$this->community->addReport((int) $user['id'], $slug, $reason, $details, $chapterId)) {
            Response::notFound();
        }
        Response::json(['data' => ['submitted' => true]], 201);
    }
}
