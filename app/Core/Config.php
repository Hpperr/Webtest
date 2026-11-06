<?php

declare(strict_types=1);

namespace TrangThu\Core;

use RuntimeException;

final class Config
{
    private static bool $loaded = false;

    public static function load(string $projectRoot): void
    {
        if (self::$loaded) {
            return;
        }

        $file = $projectRoot . DIRECTORY_SEPARATOR . '.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim(trim($value), "\"'");
                if ($name !== '' && getenv($name) === false) {
                    putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                }
            }
        }

        self::$loaded = true;
    }

    public static function get(string $name, ?string $default = null): string
    {
        $value = getenv($name);
        if ($value === false) {
            if ($default !== null) {
                return $default;
            }
            throw new RuntimeException('Missing required configuration: ' . $name);
        }

        return $value;
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
