<?php

declare(strict_types=1);

namespace TrangThu\Core;

final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return '/' . trim(is_string($path) ? $path : '/', '/');
    }

    public static function json(int $maxBytes = 65536): array
    {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            Response::json(['error' => ['code' => 'unsupported_media_type', 'message' => 'Yêu cầu phải dùng JSON.']], 415);
        }

        $body = file_get_contents('php://input');
        if ($body === false || strlen($body) > $maxBytes) {
            Response::json(['error' => ['code' => 'invalid_request', 'message' => 'Nội dung yêu cầu không hợp lệ.']], 400);
        }

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::json(['error' => ['code' => 'invalid_json', 'message' => 'JSON không hợp lệ.']], 400);
        }

        if (!is_array($decoded)) {
            Response::json(['error' => ['code' => 'invalid_json', 'message' => 'JSON phải là một đối tượng.']], 400);
        }

        return $decoded;
    }
}
