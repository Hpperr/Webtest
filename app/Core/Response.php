<?php

declare(strict_types=1);

namespace TrangThu\Core;

final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function notFound(): never
    {
        self::json(['error' => ['code' => 'not_found', 'message' => 'Không tìm thấy tài nguyên.']], 404);
    }
}
