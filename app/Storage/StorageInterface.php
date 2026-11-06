<?php

declare(strict_types=1);

namespace TrangThu\Storage;

interface StorageInterface
{
    public function storeUpload(array $file, string $extension): string;

    public function pathFor(string $relativePath): string;

    public function delete(string $relativePath): void;
}
