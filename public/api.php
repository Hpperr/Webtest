<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use TrangThu\Auth\AuthController;
use TrangThu\Books\BookController;
use TrangThu\Books\BookRepository;
use TrangThu\Books\ChapterSubmissionController;
use TrangThu\Books\ChapterSubmissionRepository;
use TrangThu\Core\Config;
use TrangThu\Core\Database;
use TrangThu\Core\Request;
use TrangThu\Core\Response;
use TrangThu\Community\CommunityController;
use TrangThu\Community\CommunityRepository;
use TrangThu\Library\LibraryController;
use TrangThu\Library\LibraryRepository;
use TrangThu\Moderation\ModerationController;
use TrangThu\Moderation\ModerationRepository;
use TrangThu\Notifications\NotificationController;
use TrangThu\Notifications\NotificationRepository;
use TrangThu\Import\ImportController;
use TrangThu\Import\ImportRepository;
use TrangThu\Storage\LocalFilesystemStorage;
use TrangThu\Reading\ReadingController;
use TrangThu\Reading\ReadingRepository;

$method = Request::method();
$path = Request::path();

if ($path === '/api/health' && $method === 'GET') {
    Response::json(['data' => ['status' => Database::healthCheck() ? 'ok' : 'database_unavailable']]);
}

$auth = new AuthController(Database::connection());
if ($path === '/api/session' && $method === 'GET') {
    $auth->session();
}
if ($path === '/api/auth/register' && $method === 'POST') {
    $auth->register();
}
if ($path === '/api/auth/login' && $method === 'POST') {
    $auth->login();
}
if ($path === '/api/auth/logout' && $method === 'POST') {
    $auth->logout();
}
if ($path === '/api/realtime-token' && $method === 'GET') {
    $auth->realtimeToken();
}

$books = new BookController(new BookRepository(Database::connection()));
$chapterSubmissions = new ChapterSubmissionController(new ChapterSubmissionRepository(Database::connection()));
$library = new LibraryController(new LibraryRepository(Database::connection()));
$reading = new ReadingController(new ReadingRepository(Database::connection()));
$community = new CommunityController(new CommunityRepository(Database::connection()));
$moderation = new ModerationController(new ModerationRepository(Database::connection()));
$notifications = new NotificationController(new NotificationRepository(Database::connection()));
$notificationPrefix = '/api/me/notifications';
if ($path === $notificationPrefix && $method === 'GET') {
    $notifications->index();
}
if (preg_match('#^/api/me/notifications/([1-9][0-9]*)/read$#D', $path, $matches) === 1 && $method === 'POST') {
    $notifications->markRead((int) $matches[1]);
}
$imports = new ImportController(
    new ImportRepository(Database::connection()),
    new LocalFilesystemStorage(dirname(__DIR__), Config::get('STORAGE_LOCAL_PATH', 'storage/uploads'))
);
if ($path === '/api/me/imports' && $method === 'GET') {
    $imports->index();
}
if ($path === '/api/me/imports' && $method === 'POST') {
    $imports->create();
}
if ($path === '/api/admin/overview' && $method === 'GET') {
    $moderation->overview();
}
if ($path === '/api/admin/books' && $method === 'GET') {
    $moderation->books();
}
if ($path === '/api/admin/chapters' && $method === 'GET') {
    $moderation->chapters();
}
if ($path === '/api/admin/reports' && $method === 'GET') {
    $moderation->reports();
}
if ($path === '/api/admin/comments' && $method === 'GET') {
    $moderation->comments();
}
if ($path === '/api/admin/users' && $method === 'GET') {
    $moderation->users();
}
if (preg_match('#^/api/admin/books/([1-9][0-9]*)$#D', $path, $matches) === 1 && $method === 'PATCH') {
    $moderation->updateBook((int) $matches[1]);
}
if (preg_match('#^/api/admin/chapters/([1-9][0-9]*)$#D', $path, $matches) === 1 && $method === 'PATCH') {
    $moderation->updateChapter((int) $matches[1]);
}
if (preg_match('#^/api/admin/reports/([1-9][0-9]*)$#D', $path, $matches) === 1 && $method === 'PATCH') {
    $moderation->updateReport((int) $matches[1]);
}
if (preg_match('#^/api/admin/comments/([1-9][0-9]*)$#D', $path, $matches) === 1 && $method === 'PATCH') {
    $moderation->updateComment((int) $matches[1]);
}
if (preg_match('#^/api/admin/users/([1-9][0-9]*)$#D', $path, $matches) === 1 && $method === 'PATCH') {
    $moderation->updateUser((int) $matches[1]);
}
if ($path === '/api/me/library' && $method === 'GET') {
    $library->index();
}
if (preg_match('#^/api/me/library/([a-z0-9][a-z0-9-]{0,99})$#D', $path, $matches) === 1) {
    $slug = $matches[1];
    if ($method === 'GET') {
        $library->show($slug);
    }
    if ($method === 'PUT') {
        $library->save($slug);
    }
    if ($method === 'DELETE') {
        $library->remove($slug);
    }
}
if ($path === '/api/me/reading-history' && $method === 'GET') {
    $reading->history();
}
if ($path === '/api/me/reading-history' && $method === 'PUT') {
    $reading->saveProgress();
}
if (preg_match('#^/api/books/([a-z0-9][a-z0-9-]{0,99})/community$#D', $path, $matches) === 1 && $method === 'GET') {
    $community->show($matches[1]);
}
if (preg_match('#^/api/books/([a-z0-9][a-z0-9-]{0,99})/comments$#D', $path, $matches) === 1 && $method === 'POST') {
    $community->comment($matches[1]);
}
if (preg_match('#^/api/books/([a-z0-9][a-z0-9-]{0,99})/reports$#D', $path, $matches) === 1 && $method === 'POST') {
    $community->report($matches[1]);
}
if (preg_match('#^/api/me/books/([a-z0-9][a-z0-9-]{0,99})/follow$#D', $path, $matches) === 1) {
    if ($method === 'PUT') {
        $community->follow($matches[1], true);
    }
    if ($method === 'DELETE') {
        $community->follow($matches[1], false);
    }
}
if (preg_match('#^/api/me/books/([a-z0-9][a-z0-9-]{0,99})/chapters$#D', $path, $matches) === 1 && $method === 'POST') {
    $chapterSubmissions->submit($matches[1]);
}
if (preg_match('#^/api/me/books/([a-z0-9][a-z0-9-]{0,99})/reaction$#D', $path, $matches) === 1) {
    if ($method === 'PUT') {
        $community->react($matches[1]);
    }
    if ($method === 'DELETE') {
        $community->removeReaction($matches[1]);
    }
}
if ($path === '/api/genres' && $method === 'GET') {
    $books->genres();
}
if ($path === '/api/books' && $method === 'GET') {
    $books->index();
}
if (preg_match('#^/api/books/([a-z0-9][a-z0-9-]{0,99})$#D', $path, $matches) === 1 && $method === 'GET') {
    $books->show($matches[1]);
}
if (preg_match('#^/api/chapters/([1-9][0-9]*)$#D', $path, $matches) === 1 && $method === 'GET') {
    $books->chapter((int) $matches[1]);
}

Response::notFound();
