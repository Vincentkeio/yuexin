<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const YX_REMEMBER_COOKIE = 'yuexin_remember';
const YX_REMEMBER_DAYS = 30;

function yx_cookie_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function yx_set_cookie(string $name, string $value, int $expires): void
{
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => yx_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function yx_clear_remember_cookie(): void
{
    yx_set_cookie(YX_REMEMBER_COOKIE, '', time() - 3600);
}

function yx_remember_user(int $userId): void
{
    $pdo = db();

    $selector = bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));
    $hash = password_hash($token, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + YX_REMEMBER_DAYS * 86400);

    // 同一账号最多保留 5 个记住登录设备
    $stmt = $pdo->prepare('
        SELECT id
        FROM remember_tokens
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 100
    ');
    $stmt->execute([$userId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($ids) >= 5) {
        $remove = array_slice($ids, 4);
        if ($remove) {
            $in = implode(',', array_fill(0, count($remove), '?'));
            $pdo->prepare("DELETE FROM remember_tokens WHERE id IN ($in)")->execute($remove);
        }
    }

    $stmt = $pdo->prepare('
        INSERT INTO remember_tokens(user_id, selector, token_hash, expires_at)
        VALUES(?,?,?,?)
    ');
    $stmt->execute([$userId, $selector, $hash, $expiresAt]);

    yx_set_cookie(YX_REMEMBER_COOKIE, $selector . ':' . $token, time() + YX_REMEMBER_DAYS * 86400);
}

function yx_try_remember_login(): void
{
    if (!empty($_SESSION['user_id'])) {
        return;
    }

    $cookie = $_COOKIE[YX_REMEMBER_COOKIE] ?? '';
    if (!$cookie || !str_contains($cookie, ':')) {
        return;
    }

    [$selector, $token] = explode(':', $cookie, 2);

    if (!preg_match('/^[a-f0-9]{32}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        yx_clear_remember_cookie();
        return;
    }

    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT rt.*, u.status
        FROM remember_tokens rt
        JOIN users u ON u.id = rt.user_id
        WHERE rt.selector = ?
        LIMIT 1
    ');
    $stmt->execute([$selector]);
    $row = $stmt->fetch();

    if (!$row) {
        yx_clear_remember_cookie();
        return;
    }

    if ((int)$row['status'] !== 1 || strtotime($row['expires_at']) < time()) {
        $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
        yx_clear_remember_cookie();
        return;
    }

    if (!password_verify($token, $row['token_hash'])) {
        $pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
        yx_clear_remember_cookie();
        return;
    }

    $_SESSION['user_id'] = (int)$row['user_id'];

    // 轮换 token，减少 Cookie 泄露风险
    $newToken = bin2hex(random_bytes(32));
    $newHash = password_hash($newToken, PASSWORD_DEFAULT);
    $newExpires = date('Y-m-d H:i:s', time() + YX_REMEMBER_DAYS * 86400);

    $pdo->prepare('
        UPDATE remember_tokens
        SET token_hash = ?, expires_at = ?, last_used_at = NOW()
        WHERE selector = ?
    ')->execute([$newHash, $newExpires, $selector]);

    yx_set_cookie(YX_REMEMBER_COOKIE, $selector . ':' . $newToken, time() + YX_REMEMBER_DAYS * 86400);
}

function current_user(): ?array
{
    yx_try_remember_login();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id,username,email,status FROM users WHERE id=? AND status=1');
    $stmt->execute([$_SESSION['user_id']]);

    return $stmt->fetch() ?: null;
}

function require_login(): array
{
    $u = current_user();

    if (!$u) {
        redirect('login.php');
    }

    return $u;
}

function login_user(int $id, bool $remember = false): void
{
    // 登录成功后更换会话 ID，防止会话固定攻击。
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;

    if ($remember) {
        yx_remember_user($id);
    }
}

function logout_user(): void
{
    $cookie = $_COOKIE[YX_REMEMBER_COOKIE] ?? '';

    if ($cookie && str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);

        if (preg_match('/^[a-f0-9]{32}$/', $selector)) {
            try {
                db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    yx_clear_remember_cookie();

    $_SESSION = [];

    if (session_id()) {
        session_destroy();
    }
}
