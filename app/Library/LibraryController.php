<?php

declare(strict_types=1);

namespace TrangThu\Library;

use TrangThu\Core\Auth;
use TrangThu\Core\Csrf;
use TrangThu\Core\Response;

final class LibraryController
{
    public function __construct(private readonly LibraryRepository $library)
    {
    }

    public function index(): never
    {
        $user = Auth::requireUser();
        Response::json(['data' => $this->library->items((int) $user['id'])]);
    }

    public function show(string $slug): never
    {
        $user = Auth::requireUser();
        $saved = $this->library->isSaved((int) $user['id'], $slug);
        if ($saved === null) {
            Response::notFound();
        }
        Response::json(['data' => ['saved' => $saved]]);
    }

    public function save(string $slug): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        if (!$this->library->save((int) $user['id'], $slug)) {
            Response::notFound();
        }
        Response::json(['data' => ['saved' => true]], 201);
    }

    public function remove(string $slug): never
    {
        Csrf::verify();
        $user = Auth::requireUser();
        if ($this->library->remove((int) $user['id'], $slug) === null) {
            Response::notFound();
        }
        Response::json(['data' => ['saved' => false]]);
    }
}
