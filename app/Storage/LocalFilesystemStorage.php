<?php

declare(strict_types=1);

namespace TrangThu\Storage;

use RuntimeException;

final class LocalFilesystemStorage implements StorageInterface
{
    private string $root;

    public function __construct(string $projectRoot, string $configuredPath)
    {
        $absolute = preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\\\\\\\\|\\/)/', $configuredPath) === 1;
        $this->root = rtrim(
            $absolute ? $configuredPath : $projectRoot . DIRECTORY_SEPARATOR . $configuredPath,
            DIRECTORY_SEPARATOR
        );
    }

    public function storeUpload(array $file, string $extension): string
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'imports';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Không thể tạo thư mục lưu tệp nhập.');
        }

        $relativePath = 'imports/' . bin2hex(random_bytes(24)) . '.' . $extension;
        $destination = $this->pathFor($relativePath);
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
            throw new RuntimeException('Không thể lưu tệp tải lên.');
        }

        return $relativePath;
    }

    public function pathFor(string $relativePath): string
    {
        if ($relativePath === '' || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $relativePath) === 1
            || preg_match('#^[\\\\/]#', $relativePath) === 1) {
            throw new RuntimeException('Đường dẫn lưu trữ không hợp lệ.');
        }

        return $this->root . DIRECTORY_SEPARATOR . str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            $relativePath
        );
    }

    public function delete(string $relativePath): void
    {
        $path = $this->pathFor($relativePath);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Không thể xóa tệp nhập tạm.');
        }
    }
}
