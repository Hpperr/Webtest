<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use TrangThu\Core\Config;
use TrangThu\Core\Database;
use TrangThu\Import\ChapterSplitter;
use TrangThu\Import\DocumentExtractor;
use TrangThu\Import\ImportRepository;
use TrangThu\Storage\LocalFilesystemStorage;

$repository = new ImportRepository(Database::connection());
$job = $repository->claimNext();
if ($job === null) {
    fwrite(STDOUT, "No queued import jobs.\n");
    exit(0);
}

$storage = new LocalFilesystemStorage(dirname(__DIR__, 2), Config::get('STORAGE_LOCAL_PATH', 'storage/uploads'));
try {
    $path = $storage->pathFor((string) $job['source_path']);
    $text = (new DocumentExtractor())->extract($path, (string) $job['source_type']);
    $wordLimit = (int) Config::get('IMPORT_CHAPTER_WORD_LIMIT', '7000');
    $chapters = (new ChapterSplitter($wordLimit))->split($text);
    if ($chapters === []) {
        throw new RuntimeException('Không tìm thấy đoạn văn bản để tạo chương.');
    }
    $repository->complete((int) $job['id'], (int) $job['book_id'], $chapters);
    fwrite(STDOUT, sprintf("Import job %d is ready for moderation (%d chapter parts).\n", $job['id'], count($chapters)));
} catch (Throwable $error) {
    $repository->fail((int) $job['id'], $error->getMessage());
    error_log(sprintf('[ERROR] [Import worker] Job %d failed: %s', $job['id'], $error->getMessage()));
    fwrite(STDERR, sprintf("Import job %d failed: %s\n", $job['id'], $error->getMessage()));
    exit(1);
}
