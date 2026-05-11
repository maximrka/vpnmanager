<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class App
{
    private Config $config;
    private Db $db;
    private AuthService $auth;
    private AuditService $audit;
    private VpnService $vpn;
    private TotpService $totp;
    private SecretCipher $cipher;
    private PDO $pdo;

    public function __construct()
    {
        $this->config = new Config();
        $this->db = new Db($this->config);
        $this->pdo = $this->db->pdo();
        $this->auth = new AuthService($this->pdo);
        $this->audit = new AuditService($this->pdo);
        $this->vpn = new VpnService($this->config, $this->pdo);
        $this->totp = new TotpService();
        $this->cipher = new SecretCipher($this->config);
    }

    public function run(): void
    {
        $route = $_GET['r'] ?? 'dashboard';

        if ($route === 'login') {
            $this->loginPage();
            return;
        }
        if ($route === 'login-2fa') {
            $this->login2faPage();
            return;
        }
        if ($route === 'logout') {
            $this->auth->logout();
            header('Location: /?r=login');
            exit;
        }

        if (!$this->auth->isLoggedIn()) {
            header('Location: /?r=login');
            exit;
        }

        if ($route === 'profile') {
            $this->profilePage();
            return;
        }
        if ($route === 'profile-2fa-enable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->enable2fa();
            return;
        }
        if ($route === 'clients-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->createClient();
            return;
        }
        if ($route === 'clients-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->toggleClient();
            return;
        }
        if ($route === 'clients-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->deleteClient();
            return;
        }
        if ($route === 'service-action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->serviceAction();
            return;
        }
        if ($route === 'clients-download') {
            $this->downloadClientConfig();
            return;
        }
        $this->dashboardPage();
    }

    private function loginPage(): void
    {
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($this->isRateLimited('login', ($username !== '' ? $username : '_') . '|' . $this->clientIp(), 8, 900)) {
                $error = 'Too many attempts. Try again later.';
                View::render('login', [
                    'appName' => $this->config->get('APP_NAME', 'VPN Web Panel'),
                    'logoText' => $this->config->get('APP_LOGO_TEXT', 'VPNWEB'),
                    'error' => $error,
                    'mode' => 'password',
                ]);
                return;
            }
            $user = $this->auth->authenticate($username, $password);

            if ($user !== null) {
                if ($this->is2faEnabled((int)$user['id'])) {
                    $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
                    $_SESSION['pending_2fa_username'] = (string)$user['username'];
                    header('Location: /?r=login-2fa');
                    exit;
                }

                $this->auth->completeLogin((int)$user['id'], (string)$user['username']);
                $this->clearRateLimit('login', ($username !== '' ? $username : '_') . '|' . $this->clientIp());
                $this->audit->log($this->auth->userId(), 'login', 'ok');
                header('Location: /');
                exit;
            }

            $this->recordRateLimitFailure('login', ($username !== '' ? $username : '_') . '|' . $this->clientIp());
            $this->audit->log(null, 'login', 'fail', ['target_id' => $username]);
            $error = 'Invalid credentials';
        }

        View::render('login', [
            'appName' => $this->config->get('APP_NAME', 'VPN Web Panel'),
            'logoText' => $this->config->get('APP_LOGO_TEXT', 'VPNWEB'),
            'error' => $error,
            'mode' => 'password',
        ]);
    }

    private function login2faPage(): void
    {
        if (!isset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_username'])) {
            header('Location: /?r=login');
            exit;
        }

        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $code = trim((string)($_POST['code'] ?? ''));
            $uid = (int)$_SESSION['pending_2fa_user_id'];
            $username = (string)$_SESSION['pending_2fa_username'];
            if ($this->isRateLimited('login2fa', $username . '|' . $this->clientIp(), 8, 900)) {
                $error = 'Too many attempts. Try again later.';
                View::render('login', [
                    'appName' => $this->config->get('APP_NAME', 'VPN Web Panel'),
                    'logoText' => $this->config->get('APP_LOGO_TEXT', 'VPNWEB'),
                    'error' => $error,
                    'mode' => 'totp',
                    'pendingUsername' => (string)$_SESSION['pending_2fa_username'],
                ]);
                return;
            }

            if ($this->verifyTotpForUser($uid, $code) || $this->consumeBackupCode($uid, $code)) {
                $this->auth->completeLogin($uid, $username);
                $this->clearRateLimit('login2fa', $username . '|' . $this->clientIp());
                $this->audit->log($uid, 'login.2fa', 'ok');
                header('Location: /');
                exit;
            }

            $this->recordRateLimitFailure('login2fa', $username . '|' . $this->clientIp());
            $error = 'Invalid 2FA or backup code';
            $this->audit->log($uid, 'login.2fa', 'fail');
        }

        View::render('login', [
            'appName' => $this->config->get('APP_NAME', 'VPN Web Panel'),
            'logoText' => $this->config->get('APP_LOGO_TEXT', 'VPNWEB'),
            'error' => $error,
            'mode' => 'totp',
            'pendingUsername' => (string)$_SESSION['pending_2fa_username'],
        ]);
    }

    private function dashboardPage(): void
    {
        $selectedQr = null;
        $selectedQrName = null;
        if ($this->vpn->backend() === 'wireguard' && isset($_GET['qr_id'])) {
            try {
                $cfg = $this->vpn->getClientConfig((int)$_GET['qr_id']);
                $selectedQr = $this->qrDataUri($cfg['content']);
                $selectedQrName = $cfg['name'];
            } catch (RuntimeException $e) {
                $_SESSION['flash_err'] = 'QR unavailable for this client';
            }
        }

        View::render('dashboard', [
            'appName' => $this->config->get('APP_NAME', 'VPN Web Panel'),
            'logoText' => $this->config->get('APP_LOGO_TEXT', 'VPNWEB'),
            'backend' => $this->vpn->backend(),
            'status' => $this->vpn->serviceStatus(),
            'clients' => $this->vpn->clients(),
            'csrf' => Csrf::token(),
            'audit' => $this->audit->last(10),
            'username' => $_SESSION['username'] ?? 'admin',
            'selectedQr' => $selectedQr,
            'selectedQrName' => $selectedQrName,
        ]);
    }

    private function profilePage(): void
    {
        $issuer = $this->config->get('TOTP_ISSUER', 'VPN Web Panel') ?? 'VPN Web Panel';
        $username = (string)($_SESSION['username'] ?? 'admin');
        $uid = (int)$this->auth->userId();
        $enabled = $this->is2faEnabled($uid);

        if (empty($_SESSION['2fa_setup_secret'])) {
            $_SESSION['2fa_setup_secret'] = $this->totp->randomSecret();
        }

        $secret = (string)$_SESSION['2fa_setup_secret'];
        $otpauth = $this->totp->otpauthUrl($issuer, $username, $secret);
        $qrData = $this->qrDataUri($otpauth);

        View::render('profile', [
            'appName' => $this->config->get('APP_NAME', 'VPN Web Panel'),
            'logoText' => $this->config->get('APP_LOGO_TEXT', 'VPNWEB'),
            'username' => $username,
            'otpauth' => $otpauth,
            'secret' => $secret,
            'qrData' => $qrData,
            'enabled' => $enabled,
            'csrf' => Csrf::token(),
            'backupCodes' => $_SESSION['new_backup_codes'] ?? [],
        ]);
        unset($_SESSION['new_backup_codes']);
    }

    private function enable2fa(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Bad CSRF token';
            return;
        }

        $uid = (int)$this->auth->userId();
        $code = trim((string)($_POST['totp_code'] ?? ''));
        $secret = (string)($_SESSION['2fa_setup_secret'] ?? '');

        if ($secret === '' || !$this->totp->verifyCode($secret, $code)) {
            $_SESSION['flash_err'] = 'Invalid TOTP code';
            header('Location: /?r=profile');
            exit;
        }

        $stmt = $this->pdo->prepare('INSERT INTO user_2fa(user_id, totp_secret_enc, is_enabled, enabled_at) VALUES (:uid,:s,1,datetime(\'now\')) ON CONFLICT(user_id) DO UPDATE SET totp_secret_enc=:s, is_enabled=1, enabled_at=datetime(\'now\')');
        $stmt->execute([':uid' => $uid, ':s' => $this->cipher->encrypt($secret)]);

        $this->pdo->prepare('DELETE FROM user_backup_codes WHERE user_id=:uid')->execute([':uid' => $uid]);
        $plainCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $plain = strtoupper(bin2hex(random_bytes(4)));
            $plainCodes[] = $plain;
            $this->pdo->prepare('INSERT INTO user_backup_codes(user_id, code_hash, created_at) VALUES (:uid,:h,datetime(\'now\'))')
                ->execute([':uid' => $uid, ':h' => password_hash($plain, PASSWORD_DEFAULT)]);
        }

        $_SESSION['new_backup_codes'] = $plainCodes;
        unset($_SESSION['2fa_setup_secret']);
        $this->audit->log($uid, '2fa.enable', 'ok');
        $_SESSION['flash_ok'] = '2FA enabled';
        header('Location: /?r=profile');
        exit;
    }

    private function createClient(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Bad CSRF token';
            return;
        }

        $name = trim((string)($_POST['client_name'] ?? ''));
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $name)) {
            $_SESSION['flash_err'] = 'Client name must be 3-32 chars: letters, digits, _ or -';
            header('Location: /');
            exit;
        }

        $uid = (int)$this->auth->userId();

        try {
            $this->vpn->createClient($name, $uid);
            $this->audit->log($uid, 'client.create', 'ok', ['target_id' => $name]);
            $_SESSION['flash_ok'] = 'Client created: ' . $name;
        } catch (RuntimeException $e) {
            $this->audit->log($uid, 'client.create', 'fail', ['target_id' => $name, 'error' => $e->getMessage()]);
            $_SESSION['flash_err'] = 'Create failed: ' . $e->getMessage();
        }

        header('Location: /');
        exit;
    }

    private function toggleClient(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Bad CSRF token';
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        $to = (string)($_POST['to'] ?? 'disabled');
        $next = $to === 'active' ? 'active' : 'disabled';
        try {
            $this->vpn->setClientStatus($id, $next);
            $this->audit->log($this->auth->userId(), 'client.toggle', 'ok', ['target_id' => (string)$id, 'to' => $next]);
            $_SESSION['flash_ok'] = 'Client ' . ($next === 'active' ? 'enabled' : 'disabled');
        } catch (RuntimeException $e) {
            $this->audit->log($this->auth->userId(), 'client.toggle', 'fail', ['target_id' => (string)$id, 'to' => $next, 'error' => $e->getMessage()]);
            $_SESSION['flash_err'] = 'Toggle failed: ' . $e->getMessage();
        }
        header('Location: /');
        exit;
    }

    private function deleteClient(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Bad CSRF token';
            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        try {
            $this->vpn->deleteClient($id);
            $this->audit->log($this->auth->userId(), 'client.delete', 'ok', ['target_id' => (string)$id]);
            $_SESSION['flash_ok'] = 'Client revoked';
        } catch (RuntimeException $e) {
            $this->audit->log($this->auth->userId(), 'client.delete', 'fail', ['target_id' => (string)$id, 'error' => $e->getMessage()]);
            $_SESSION['flash_err'] = 'Revoke failed: ' . $e->getMessage();
        }
        header('Location: /');
        exit;
    }

    private function serviceAction(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Bad CSRF token';
            return;
        }

        $action = (string)($_POST['action'] ?? 'restart');
        $this->vpn->serviceAction($action);
        $this->audit->log($this->auth->userId(), 'service.' . $action, 'ok', ['target_id' => $this->vpn->backend()]);
        header('Location: /');
        exit;
    }

    private function downloadClientConfig(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        try {
            $cfg = $this->vpn->getClientConfig($id);
            $ext = $this->vpn->backend() === 'openvpn' ? 'ovpn' : 'conf';
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $cfg['name'] . '.' . $ext . '"');
            echo $cfg['content'];
            $this->audit->log($this->auth->userId(), 'client.download', 'ok', ['target_id' => (string)$id]);
        } catch (RuntimeException $e) {
            http_response_code(404);
            echo 'Config not available';
            $this->audit->log($this->auth->userId(), 'client.download', 'fail', ['target_id' => (string)$id]);
        }
    }

    private function is2faEnabled(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT is_enabled FROM user_2fa WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && (int)$row['is_enabled'] === 1;
    }

    private function verifyTotpForUser(int $userId, string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT totp_secret_enc, is_enabled FROM user_2fa WHERE user_id=:uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['is_enabled'] !== 1) {
            return false;
        }

        $stored = (string)$row['totp_secret_enc'];
        $secret = $this->cipher->decrypt($stored);
        if ($secret === null) {
            $legacy = base64_decode($stored, true);
            if (!is_string($legacy) || $legacy === '') {
                return false;
            }
            $secret = $legacy;
        }

        return $this->totp->verifyCode($secret, $code);
    }

    private function consumeBackupCode(int $userId, string $code): bool
    {
        $clean = strtoupper(trim($code));
        if ($clean === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT id, code_hash FROM user_backup_codes WHERE user_id=:uid AND used_at IS NULL');
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            if (password_verify($clean, (string)$row['code_hash'])) {
                $this->pdo->prepare('UPDATE user_backup_codes SET used_at=datetime(\'now\') WHERE id=:id')
                    ->execute([':id' => (int)$row['id']]);
                return true;
            }
        }

        return false;
    }

    private function qrDataUri(string $payload): ?string
    {
        $png = shell_exec('qrencode -t PNG -o - ' . escapeshellarg($payload) . ' 2>/dev/null');
        if (!is_string($png) || $png === '') {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode($png);
    }

    private function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    private function recordRateLimitFailure(string $scope, string $key): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO login_attempts(scope, key_value, created_at) VALUES (:s, :k, datetime(\'now\'))');
        $stmt->execute([':s' => $scope, ':k' => $key]);
    }

    private function isRateLimited(string $scope, string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $del = $this->pdo->prepare('DELETE FROM login_attempts WHERE created_at < datetime(\'now\', :w)');
        $del->execute([':w' => '-' . $windowSeconds . ' seconds']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE scope=:s AND key_value=:k');
        $stmt->execute([':s' => $scope, ':k' => $key]);
        $count = (int)$stmt->fetchColumn();
        return $count >= $maxAttempts;
    }

    private function clearRateLimit(string $scope, string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE scope=:s AND key_value=:k');
        $stmt->execute([':s' => $scope, ':k' => $key]);
    }
}
