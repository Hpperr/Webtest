<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'app/bootstrap.php',
    'app/Core/Config.php',
    'app/Core/Database.php',
    'app/Core/Request.php',
    'app/Core/Response.php',
    'app/Core/Csrf.php',
    'app/Core/Auth.php',
    'app/Auth/AuthController.php',
    'app/Books/BookRepository.php',
    'app/Books/BookController.php',
    'app/Books/ChapterSubmissionRepository.php',
    'app/Books/ChapterSubmissionController.php',
    'tests/chapter-workflow.php',
    'app/Reading/ReadingRepository.php',
    'app/Reading/ReadingController.php',
    'app/Library/LibraryRepository.php',
    'app/Library/LibraryController.php',
    'app/Community/CommunityRepository.php',
    'app/Community/CommunityController.php',
    'app/Moderation/ModerationRepository.php',
    'app/Moderation/ModerationController.php',
    'app/Notifications/NotificationRepository.php',
    'app/Notifications/NotificationController.php',
    'app/Storage/StorageInterface.php',
    'app/Storage/LocalFilesystemStorage.php',
    'app/Import/ImportRepository.php',
    'app/Import/DocumentExtractor.php',
    'app/Import/ChapterSplitter.php',
    'app/Import/ImportController.php',
    'app/workers/import-worker.php',
    'app/workers/notification-outbox-worker.php',
    'public/api.php',
    'router.php',
];

$failures = [];
foreach ($files as $file) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . DIRECTORY_SEPARATOR . $file) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $failures[] = $file . ': ' . implode("\n", $output);
    }
    $output = [];
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, count($files) . " PHP files passed syntax checks.\n");
$testCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'importer.php');
exec($testCommand . ' 2>&1', $output, $status);
if ($status !== 0) {
    fwrite(STDERR, implode("\n", $output) . "\n");
    exit($status);
}
fwrite(STDOUT, implode("\n", $output) . "\n");
