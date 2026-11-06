<?php

declare(strict_types=1);

namespace TrangThu\Notifications;

use TrangThu\Core\Auth;
use TrangThu\Core\Csrf;
use TrangThu\Core\Response;

final class NotificationController
{
    public function __construct(private readonly NotificationRepository $notifications)
    {
    }

    public function index(): never
    {
        $user = Auth::requireUser();
        Response::json(['data' => $this->notifications->forUser((int) $user['id'])]);
    }

    public function markRead(int $notificationId): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        if (!$this->notifications->markRead((int) $user['id'], $notificationId)) {
            Response::notFound();
        }
        Response::json(['data' => ['read' => true]]);
    }
}
