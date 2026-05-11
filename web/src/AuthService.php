<?php

declare(strict_types=1);

namespace App;

use PDO;

final class AuthService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function authenticate(string $username, string $password): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash, is_active FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['is_active'] !== 1) {
            return null;
        }

        if (!password_verify($password, (string)$user['password_hash'])) {
            return null;
        }

        return ['id' => (int)$user['id'], 'username' => (string)$user['username']];
    }

    public function completeLogin(int $userId, string $username): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username']);
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
    }

    public function userId(): ?int
    {
        return $this->isLoggedIn() ? (int)$_SESSION['user_id'] : null;
    }
}
