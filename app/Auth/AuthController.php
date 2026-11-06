<?php

declare(strict_types=1);

namespace TrangThu\Auth;

use PDO;
use TrangThu\Core\Auth;
use TrangThu\Core\Config;
use TrangThu\Core\Csrf;
use TrangThu\Core\Request;
use TrangThu\Core\Response;

final class AuthController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function session(): never
    {
        Response::json(['data' => ['user' => Auth::user(), 'csrf_token' => Csrf::token()]]);
    }

    public function register(): never
    {
        Csrf::verify();
        $input = Request::json();
        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = $input['password'] ?? null;

        if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 190 || !is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            Response::json(['error' => ['code' => 'validation_error', 'message' => 'Hãy nhập tên, email hợp lệ và mật khẩu từ 12 ký tự.']], 422);
        }

        $statement = $this->db->prepare('INSERT INTO users (display_name, email, password_hash, role, status) VALUES (:name, :email, :password_hash, \'reader\', \'active\')');
        try {
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (\PDOException $error) {
            if ($error->getCode() === '23000') {
                Response::json(['error' => ['code' => 'email_unavailable', 'message' => 'Email này đã được sử dụng.']], 409);
            }
            throw $error;
        }

        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int) $this->db->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'reader'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        Response::json(['data' => ['user' => $_SESSION['user'], 'csrf_token' => Csrf::token()]], 201);
    }

    public function login(): never
    {
        Csrf::verify();
        $input = Request::json();
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = $input['password'] ?? null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || strlen($password) > 72) {
            Response::json(['error' => ['code' => 'invalid_credentials', 'message' => 'Email hoặc mật khẩu không chính xác.']], 401);
        }

        $identity = $this->loginIdentity($email);
        $throttle = $this->db->prepare(
            'SELECT attempts FROM login_attempts WHERE identity_hash = :identity_hash '
            . 'AND window_started_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) LIMIT 1'
        );
        $throttle->execute(['identity_hash' => $identity]);
        if ((int) ($throttle->fetchColumn() ?: 0) >= 5) {
            Response::json(['error' => ['code' => 'too_many_attempts', 'message' => 'Bạn đã thử đăng nhập quá nhiều lần. Hãy đợi 15 phút rồi thử lại.']], 429);
        }

        $statement = $this->db->prepare('SELECT id, display_name, email, password_hash, role FROM users WHERE email = :email AND status = \'active\' LIMIT 1');
        $statement->execute(['email' => $email]);
        $record = $statement->fetch();
        if (!is_array($record) || !password_verify($password, $record['password_hash'])) {
            $recordAttempt = $this->db->prepare(
                'INSERT INTO login_attempts (identity_hash, attempts, window_started_at) VALUES (:identity_hash, 1, UTC_TIMESTAMP()) '
                . 'ON DUPLICATE KEY UPDATE attempts = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), 1, attempts + 1), '
                . 'window_started_at = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), UTC_TIMESTAMP(), window_started_at)'
            );
            $recordAttempt->execute(['identity_hash' => $identity]);
            Response::json(['error' => ['code' => 'invalid_credentials', 'message' => 'Email hoặc mật khẩu không chính xác.']], 401);
        }

        $this->db->prepare('DELETE FROM login_attempts WHERE identity_hash = :identity_hash')->execute(['identity_hash' => $identity]);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int) $record['id'], 'name' => $record['display_name'], 'email' => $record['email'], 'role' => $record['role']];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        Response::json(['data' => ['user' => $_SESSION['user'], 'csrf_token' => Csrf::token()]]);
    }

    public function logout(): never
    {
        Csrf::verify();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $parameters['path'], $parameters['domain'], $parameters['secure'], $parameters['httponly']);
        }
        session_destroy();
        Response::json(['data' => ['logged_out' => true]]);
    }

    public function realtimeToken(): never
    {
        $user = Auth::requireUser();
        $secret = Config::get('APP_KEY');
        if (strlen($secret) < 32) {
            Response::json(['error' => ['code' => 'realtime_unavailable', 'message' => 'Dịch vụ thông báo chưa được cấu hình.']], 503);
        }

        $now = time();
        $expiresAt = $now + min(600, max(60, (int) Config::get('REALTIME_TOKEN_TTL', '300')));
        $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode([
            'sub' => (int) $user['id'],
            'role' => $user['role'],
            'iat' => $now,
            'exp' => $expiresAt,
        ]);
        $content = $header . '.' . $payload;
        $signature = hash_hmac('sha256', $content, $secret, true);
        $token = $content . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        Response::json(['data' => [
            'token' => $token,
            'expires_at' => $expiresAt,
            'realtime_url' => Config::get('REALTIME_URL', 'http://127.0.0.1:3100'),
        ]]);
    }

    private function loginIdentity(string $email): string
    {
        return hash_hmac('sha256', $email . "\0" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), Config::get('APP_KEY'));
    }

    public static function startSession(): void
    {
        session_name('trangthu_session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => Config::bool('SESSION_SECURE_COOKIE', Config::get('APP_ENV', 'local') === 'production'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
