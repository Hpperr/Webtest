<?php

declare(strict_types=1);

$database = getenv('TEST_DB_DATABASE') ?: '';
if (!preg_match('/\A[a-zA-Z0-9_]+_test\z/', $database)) {
    fwrite(STDERR, "TEST_DB_DATABASE must be a dedicated database name containing only letters, numbers, underscores, and ending in _test.\n");
    exit(2);
}

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('TEST_DB_PORT') ?: '3306';
$username = getenv('TEST_DB_USERNAME') ?: 'root';
$password = getenv('TEST_DB_PASSWORD') ?: '';
if (preg_match('/\A[a-zA-Z0-9.:-]+\z/', $host) !== 1
    || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
    fwrite(STDERR, "TEST_DB_HOST or TEST_DB_PORT is invalid.\n");
    exit(2);
}
$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

spl_autoload_register(static function (string $class): void {
    $prefix = 'TrangThu\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use TrangThu\Books\BookRepository;
use TrangThu\Books\ChapterSubmissionRepository;
use TrangThu\Community\CommunityRepository;
use TrangThu\Import\ChapterSplitter;
use TrangThu\Moderation\ModerationRepository;
use TrangThu\Notifications\NotificationRepository;
use TrangThu\Reading\ReadingRepository;

$suffix = bin2hex(random_bytes(6));
$createdUsers = [];
$createdBookId = null;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $genreId = $db->query("SELECT id FROM genres WHERE slug = 'huyen-huyen'")->fetchColumn();
    $assert($genreId !== false, 'Run database/seeds/001_genres.sql before this integration test.');

    $insertUser = $db->prepare(
        'INSERT INTO users (display_name, email, password_hash, role) VALUES (:name, :email, :password, :role)'
    );
    foreach (['owner' => 'reader', 'follower' => 'reader', 'moderator' => 'moderator'] as $name => $role) {
        $insertUser->execute([
            'name' => 'Chapter test ' . $name,
            'email' => $name . '-' . $suffix . '@test.invalid',
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'role' => $role,
        ]);
        $createdUsers[$name] = (int) $db->lastInsertId();
    }

    $slug = 'chapter-test-' . $suffix;
    $insertBook = $db->prepare(
        "INSERT INTO books (owner_id, genre_id, slug, title, author_name, description, status, visibility, moderation_status) "
        . "VALUES (:owner_id, :genre_id, :slug, :title, :author, :description, 'ongoing', 'public', 'approved')"
    );
    $insertBook->execute([
        'owner_id' => $createdUsers['owner'],
        'genre_id' => $genreId,
        'slug' => $slug,
        'title' => 'Tác phẩm kiểm thử',
        'author' => 'Tác giả kiểm thử',
        'description' => 'Fixture kiểm thử tích hợp.',
    ]);
    $createdBookId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO follows (user_id, book_id) VALUES (:user_id, :book_id)')
        ->execute(['user_id' => $createdUsers['follower'], 'book_id' => $createdBookId]);
    $insertPublishedChapter = $db->prepare(
        "INSERT INTO chapters (book_id, chapter_number, part_number, title, content, word_count, visibility, moderation_status, published_at) "
        . "VALUES (:book_id, :chapter_number, 1, :title, 'Nội dung công khai.', 2, 'public', 'approved', CURRENT_TIMESTAMP)"
    );
    for ($number = 1; $number <= 21; $number++) {
        $insertPublishedChapter->execute([
            'book_id' => $createdBookId,
            'chapter_number' => $number,
            'title' => 'Chương có sẵn ' . $number,
        ]);
    }

    $submission = new ChapterSubmissionRepository($db);
    $moderation = new ModerationRepository($db);
    $books = new BookRepository($db);
    $splitter = new ChapterSplitter();
    $parts = $splitter->splitChapter('Chương được gửi', 'Nội dung chờ kiểm duyệt.');
    $firstPage = $books->chapters($createdBookId, 20, 0);
    $secondPage = $books->chapters($createdBookId, 20, 20);
    $assert(
        count($firstPage) === 20 && count($secondPage) === 1
            && (int) $firstPage[0]['chapter_number'] === 1
            && (int) $secondPage[0]['chapter_number'] === 21,
        'Public chapter pagination did not return sequential, complete pages.'
    );
    $assert($books->countPublicChapters($createdBookId) === 21, 'Published chapter count did not reflect all pages.');

    $assert(
        $submission->submit($createdUsers['follower'], $slug, 'Gửi trái phép', $parts) === null,
        'A non-owner was allowed to submit a chapter.'
    );
    $submissionResult = $submission->submit($createdUsers['owner'], $slug, 'Chương được gửi', $parts);
    $assert($submissionResult !== null && $submissionResult['status'] === 'pending', 'Owner submission was not queued.');

    $chapterStatement = $db->prepare(
        'SELECT id FROM chapters WHERE book_id = :book_id AND chapter_number = :chapter_number AND part_number = 1'
    );
    $chapterStatement->execute(['book_id' => $createdBookId, 'chapter_number' => $submissionResult['chapter_number']]);
    $pendingChapterId = (int) $chapterStatement->fetchColumn();
    $assert($books->findPublicChapter($pendingChapterId) === null, 'A pending chapter was publicly readable.');
    $assert($books->countPublicChapters($createdBookId) === 21, 'A pending chapter was included in the public count.');
    $pendingQueue = $moderation->chapters('pending');
    $assert(
        count(array_filter($pendingQueue, static fn (array $item): bool => (int) $item['id'] === $pendingChapterId)) === 1,
        'The moderation queue did not include a pending chapter.'
    );
    $assert(
        (int) $books->findPublicBySlug($slug, $createdUsers['owner'])['can_submit_chapter'] === 1,
        'The owner was not granted the chapter submission capability.'
    );
    $assert(
        (int) $books->findPublicBySlug($slug, $createdUsers['follower'])['can_submit_chapter'] === 0,
        'A non-owner received the chapter submission capability.'
    );
    $assert(
        (new NotificationRepository($db))->forUser($createdUsers['follower'])['unread_count'] === 0,
        'Followers were notified before the chapter was approved.'
    );
    $assert(
        $moderation->updateChapter($createdUsers['moderator'], $pendingChapterId, 'approve'),
        'A moderator could not approve a pending chapter.'
    );
    $assert(
        count(array_filter(
            $moderation->chapters('pending'),
            static fn (array $item): bool => (int) $item['id'] === $pendingChapterId
        )) === 0,
        'An approved chapter remained in the pending queue.'
    );
    $assert($books->findPublicChapter($pendingChapterId) !== null, 'An approved chapter was not published.');
    $assert($books->countPublicChapters($createdBookId) === 22, 'An approved chapter was missing from the public count.');
    $community = new CommunityRepository($db);
    $assert(
        $community->addReport($createdUsers['follower'], $slug, 'copyright', 'Kiểm tra chương này.', $pendingChapterId),
        'A reader could not report an approved chapter.'
    );
    $assert(
        !$community->addReport($createdUsers['follower'], $slug, 'copyright', 'Chương không tồn tại.', 999999999),
        'A reader could report a chapter that does not belong to the book.'
    );
    $reports = (new ModerationRepository($db))->reports('active');
    $chapterReport = array_values(array_filter(
        $reports,
        static fn (array $item): bool => (int) $item['chapter_id'] === $pendingChapterId
    ));
    $assert(
        count($chapterReport) === 1 && $chapterReport[0]['chapter_title'] === 'Chương được gửi',
        'The moderation report queue did not identify its reported chapter.'
    );
    $inbox = (new NotificationRepository($db))->forUser($createdUsers['follower']);
    $assert(
        $inbox['unread_count'] === 1 && $inbox['items'][0]['type'] === 'new_chapter',
        'Followers were not notified after chapter approval.'
    );

    $reading = new ReadingRepository($db);
    $assert($reading->saveProgress($createdUsers['follower'], $pendingChapterId, 25), 'Reading progress could not be saved.');
    $history = $reading->history($createdUsers['follower']);
    $assert(count($history) === 1 && (int) $history[0]['chapter_id'] === $pendingChapterId, 'Approved chapter was missing from reading history.');
    $assert($moderation->updateChapter($createdUsers['moderator'], $pendingChapterId, 'hide'), 'A moderator could not hide a published chapter.');
    $assert($books->findPublicChapter($pendingChapterId) === null, 'A hidden chapter was still publicly readable.');
    $assert(!$reading->saveProgress($createdUsers['follower'], $pendingChapterId, 50), 'Reading progress accepted a hidden chapter.');
    $assert($moderation->updateChapter($createdUsers['moderator'], $pendingChapterId, 'restore'), 'A moderator could not restore a published chapter.');
    $assert($books->findPublicChapter($pendingChapterId) !== null, 'A restored chapter was not publicly readable.');
    $assert(
        (new NotificationRepository($db))->forUser($createdUsers['follower'])['unread_count'] === 1,
        'Restoring a chapter incorrectly sent another follower notification.'
    );
    $audit = $db->prepare(
        "SELECT COUNT(*) FROM moderation_actions WHERE target_type = 'chapter' AND target_id = :target_id AND admin_id = :admin_id"
    );
    $audit->execute(['target_id' => $pendingChapterId, 'admin_id' => $createdUsers['moderator']]);
    $assert((int) $audit->fetchColumn() === 3, 'Chapter approve/hide/restore actions were not audited.');

    $rejectedResult = $submission->submit($createdUsers['owner'], $slug, 'Chương bị từ chối', $parts);
    $chapterStatement->execute(['book_id' => $createdBookId, 'chapter_number' => $rejectedResult['chapter_number']]);
    $rejectedChapterId = (int) $chapterStatement->fetchColumn();
    $assert(
        $moderation->updateChapter($createdUsers['moderator'], $rejectedChapterId, 'reject'),
        'A moderator could not reject a pending chapter.'
    );
    $assert($books->findPublicChapter($rejectedChapterId) === null, 'A rejected chapter was publicly readable.');
    $assert(
        (new NotificationRepository($db))->forUser($createdUsers['follower'])['unread_count'] === 1,
        'A rejected chapter incorrectly notified followers.'
    );

    $longChapter = $splitter->splitChapter(
        'Chương dài',
        implode("\n\n", array_fill(0, 7001, 'từ'))
    );
    $assert(
        count($longChapter) === 2 && $longChapter[1]['part_number'] === 2
            && $longChapter[1]['title'] === 'Chương dài',
        'A long chapter was not split at paragraph boundaries while retaining its title.'
    );
    $longSubmission = $submission->submit($createdUsers['owner'], $slug, 'Chương dài', $longChapter);
    $assert(
        $longSubmission !== null && $longSubmission['parts_count'] === 2,
        'A long chapter submission did not persist all sequential parts.'
    );
    fwrite(STDOUT, "Chapter submission, moderation, access control, notifications, reading history, and long-chapter checks passed.\n");
} finally {
    if ($createdBookId !== null) {
        $db->prepare(
            'DELETE FROM reports WHERE reporter_id IN (' . implode(',', array_fill(0, count($createdUsers), '?')) . ')'
        )->execute(array_values($createdUsers));
        $db->prepare('DELETE FROM books WHERE id = :id')->execute(['id' => $createdBookId]);
        $db->prepare(
            "DELETE FROM moderation_actions WHERE target_type = 'chapter' AND admin_id IN ("
            . implode(',', array_fill(0, count($createdUsers), '?')) . ')'
        )->execute(array_values($createdUsers));
    }
    if ($createdUsers !== []) {
        $db->prepare(
            'DELETE FROM users WHERE id IN (' . implode(',', array_fill(0, count($createdUsers), '?')) . ')'
        )->execute(array_values($createdUsers));
    }
}
