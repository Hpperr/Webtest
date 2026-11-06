<?php

declare(strict_types=1);

namespace TrangThu\Core;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verify(): void
    {
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
            Response::json(['error' => ['code' => 'csrf_failed', 'message' => 'Phiên bảo mật đã hết hạn. Hãy tải lại trang.']], 419);
        }
    }
}
