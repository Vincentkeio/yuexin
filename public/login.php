<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    redirect('records.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    $stmt = db()->prepare('SELECT * FROM users WHERE username=? OR email=? LIMIT 1');
    $stmt->execute([$u, $u]);
    $user = $stmt->fetch();

    if ($user && password_verify($p, $user['password_hash'])) {
        login_user((int)$user['id'], $remember);
        db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
        redirect('records.php');
    }

    $error = '账号或密码错误';
}

$title = '登录';
include __DIR__ . '/../app/layout/header.php';
?>

<div class="auth card login-card">
    <h2>登录阅心</h2>

    <?php if ($error): ?>
        <div class="alert danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <p>
            <label>用户名</label>
            <input name="username" required autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
        </p>

        <p>
            <label>密码</label>
            <input type="password" name="password" required autocomplete="current-password">
        </p>

        <p class="remember-row">
            <label class="remember-label">
                <input type="checkbox" name="remember" value="1" <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                <span>记住我的账号，30 天内自动登录</span>
            </label>
        </p>

        <p>
            <button>登录</button>
        </p>
    </form>
</div>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
