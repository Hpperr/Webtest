<?php

declare(strict_types=1);

namespace TrangThu\Import;

use finfo;
use TrangThu\Core\Auth;
use TrangThu\Core\Csrf;
use TrangThu\Core\Response;
use TrangThu\Storage\StorageInterface;

final class ImportController
{
    private const MAX_UPLOAD_BYTES = 15_000_000;
    private const MIME_TYPES = [
        'txt' => ['text/plain'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'pdf' => ['application/pdf'],
    ];

    public function __construct(
        private readonly ImportRepository $imports,
        private readonly StorageInterface $storage
    ) {
    }

    public function index(): never
    {
        $user = Auth::requireUser();
        Response::json(['data' => $this->imports->forUser((int) $user['id'])]);
    }

    public function create(): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        $title = $this->inputString('title', 3, 240);
        $author = $this->inputString('author', 2, 160);
        $genre = $this->inputString('genre', 1, 64);
        $description = $this->inputString('description', 0, 5000);
        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || !isset($file['error'], $file['size'], $file['tmp_name'], $file['name'])
            || (int) $file['error'] !== UPLOAD_ERR_OK || (int) $file['size'] < 1
            || (int) $file['size'] > self::MAX_UPLOAD_BYTES || !is_uploaded_file((string) $file['tmp_name'])) {
            Response::json(['error' => ['code' => 'invalid_upload', 'message' => 'Tệp tải lên không hợp lệ hoặc vượt quá 15 MB.']], 422);
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset(self::MIME_TYPES[$extension])) {
            Response::json(['error' => ['code' => 'unsupported_file', 'message' => 'Chỉ hỗ trợ tệp TXT, DOCX hoặc PDF.']], 422);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!is_string($mime) || !in_array($mime, self::MIME_TYPES[$extension], true)) {
            Response::json(['error' => ['code' => 'invalid_file_type', 'message' => 'Định dạng thực tế của tệp không khớp phần mở rộng.']], 422);
        }

        $path = $this->storage->storeUpload($file, $extension);
        try {
            $job = $this->imports->createQueued(
                (int) $user['id'],
                $genre,
                $title,
                $author,
                $description,
                $path,
                $extension
            );
            if ($job === null) {
                $this->storage->delete($path);
                Response::json(['error' => ['code' => 'invalid_genre', 'message' => 'Thể loại truyện không tồn tại.']], 422);
            }
        } catch (\Throwable $error) {
            try {
                $this->storage->delete($path);
            } catch (\Throwable $cleanupError) {
                error_log('[ERROR] [Import cleanup] Could not remove failed upload: ' . $cleanupError->getMessage());
            }
            throw $error;
        }

        Response::json(['data' => $job + ['status' => 'queued']], 202);
    }

    private function inputString(string $key, int $minLength, int $maxLength): string
    {
        $value = $_POST[$key] ?? null;
        if (!is_string($value)) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Thông tin truyện không hợp lệ.']], 422);
        }
        $value = trim($value);
        if (mb_strlen($value) < $minLength || mb_strlen($value) > $maxLength) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Thông tin truyện không hợp lệ.']], 422);
        }
        return $value;
    }
}
