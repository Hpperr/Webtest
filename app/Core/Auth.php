<?php

declare(strict_types=1);

namespace TrangThu\Core;

use PDO;

final class Auth
{
    public static function user(): ?array
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            Response::json(['error' => ['code' => 'unauthenticated', 'message' => 'Vui lòng đăng nhập để tiếp tục.']], 401);
        }

        $statement = Database::connection()->prepare(
            "SELECT id, display_name AS name, email, role FROM users WHERE id = :id AND status = 'active' LIMIT 1"
        );
        $statement->bindValue(':id', (int) $user['id'], PDO::PARAM_INT);
        $statement->execute();
        $activeUser = $statement->fetch();
        if (!is_array($activeUser)) {
            unset($_SESSION['user']);
            Response::json(['error' => ['code' => 'unauthenticated', 'message' => 'Phiên đăng nhập không còn hiệu lực.']], 401);
        }

        $_SESSION['user'] = [
            'id' => (int) $activeUser['id'],
            'name' => $activeUser['name'],
            'email' => $activeUser['email'],
            'role' => $activeUser['role'],
        ];
        return $_SESSION['user'];
    }

    public static function requireRole(array $allowedRoles): array
    {
        $user = self::requireUser();
        if (!in_array($user['role'], $allowedRoles, true)) {
            Response::json(['error' => ['code' => 'forbidden', 'message' => 'Bạn không có quyền thực hiện thao tác này.']], 403);
        }

        return $user;
    }
}
