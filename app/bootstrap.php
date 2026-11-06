<?php

declare(strict_types=1);

require_once __DIR__ . '/Core/Config.php';

use TrangThu\Core\Config;
use TrangThu\Core\Database;
use TrangThu\Core\Response;

Config::load(dirname(__DIR__));

$composerAutoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

spl_autoload_register(static function (string $class): void {
    $prefix = 'TrangThu\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

set_exception_handler(static function (Throwable $error): void {
    error_log('[ERROR] [Application] ' . $error->getMessage());
    Response::json(['error' => [
        'code' => 'internal_error',
        'message' => Config::bool('APP_DEBUG', false) ? $error->getMessage() : 'Đã xảy ra lỗi. Vui lòng thử lại sau.',
    ]], 500);
});

TrangThu\Auth\AuthController::startSession();
