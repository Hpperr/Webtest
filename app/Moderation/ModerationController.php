<?php

declare(strict_types=1);

namespace TrangThu\Moderation;

use TrangThu\Core\Auth;
use TrangThu\Core\Csrf;
use TrangThu\Core\Request;
use TrangThu\Core\Response;

final class ModerationController
{
    public function __construct(private readonly ModerationRepository $moderation)
    {
    }

    public function overview(): never
    {
        Auth::requireRole(['admin', 'moderator']);
        Response::json(['data' => $this->moderation->overview()]);
    }

    public function books(): never
    {
        Auth::requireRole(['admin', 'moderator']);
        $status = $this->queryStatus('moderation_status', ['pending', 'approved', 'rejected', 'all'], 'pending');
        Response::json(['data' => $this->moderation->books($status)]);
    }

    public function chapters(): never
    {
        Auth::requireRole(['admin', 'moderator']);
        $status = $this->queryStatus('moderation_status', ['pending', 'approved', 'rejected', 'all'], 'pending');
        Response::json(['data' => $this->moderation->chapters($status)]);
    }

    public function reports(): never
    {
        Auth::requireRole(['admin', 'moderator']);
        $status = $this->queryStatus('status', ['open', 'reviewing', 'resolved', 'dismissed', 'active'], 'active');
        Response::json(['data' => $this->moderation->reports($status)]);
    }

    public function comments(): never
    {
        Auth::requireRole(['admin', 'moderator']);
        $status = $this->queryStatus('status', ['visible', 'hidden', 'removed', 'all'], 'all');
        Response::json(['data' => $this->moderation->comments($status)]);
    }

    public function users(): never
    {
        Auth::requireRole(['admin']);
        Response::json(['data' => $this->moderation->users()]);
    }

    public function updateBook(int $id): never
    {
        $admin = $this->authorizeChange(['admin', 'moderator']);
        $input = Request::json();
        $action = $input['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['approve', 'reject', 'hide', 'publish'], true)) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Thao tác kiểm duyệt không hợp lệ.']], 422);
        }
        $this->saveResult($this->moderation->updateBook((int) $admin['id'], $id, $action));
    }

    public function updateChapter(int $id): never
    {
        $admin = $this->authorizeChange(['admin', 'moderator']);
        $input = Request::json();
        $action = $input['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['approve', 'reject', 'hide', 'restore'], true)) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Thao tác kiểm duyệt chương không hợp lệ.']], 422);
        }
        $this->saveResult($this->moderation->updateChapter((int) $admin['id'], $id, $action));
    }

    public function updateReport(int $id): never
    {
        $admin = $this->authorizeChange(['admin', 'moderator']);
        $input = Request::json();
        $status = $input['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['reviewing', 'resolved', 'dismissed'], true)) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Trạng thái báo cáo không hợp lệ.']], 422);
        }
        $this->saveResult($this->moderation->updateReport((int) $admin['id'], $id, $status));
    }

    public function updateComment(int $id): never
    {
        $admin = $this->authorizeChange(['admin', 'moderator']);
        $input = Request::json();
        $status = $input['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['visible', 'hidden', 'removed'], true)) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Trạng thái bình luận không hợp lệ.']], 422);
        }
        $this->saveResult($this->moderation->updateComment((int) $admin['id'], $id, $status));
    }

    public function updateUser(int $id): never
    {
        $admin = $this->authorizeChange(['admin']);
        if ($id === (int) $admin['id']) {
            Response::json(['error' => ['code' => 'invalid_target', 'message' => 'Không thể tự thay đổi quyền hoặc trạng thái tài khoản đang dùng.']], 422);
        }
        $input = Request::json();
        $role = $input['role'] ?? null;
        $status = $input['status'] ?? null;
        if (!is_string($role) || !in_array($role, ['reader', 'moderator', 'admin'], true)
            || !is_string($status) || !in_array($status, ['active', 'suspended', 'deleted'], true)) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Quyền hoặc trạng thái tài khoản không hợp lệ.']], 422);
        }
        $this->saveResult($this->moderation->updateUser((int) $admin['id'], $id, $role, $status));
    }

    private function authorizeChange(array $roles): array
    {
        Csrf::verify();
        return Auth::requireRole($roles);
    }

    private function saveResult(bool $saved): never
    {
        if (!$saved) {
            Response::notFound();
        }
        Response::json(['data' => ['updated' => true]]);
    }

    private function queryStatus(string $key, array $allowed, string $fallback): string
    {
        $status = $_GET[$key] ?? $fallback;
        if (!is_string($status) || !in_array($status, $allowed, true)) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Bộ lọc quản trị không hợp lệ.']], 422);
        }
        return $status;
    }
}
