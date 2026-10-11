<?php
require_once __DIR__ . '/../app/bootstrap.php';

$u = require_login();
$uid = (int)$u['id'];
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
$stmt->execute([$uid]);
$user = $stmt->fetch();

if (!$user) {
    die('用户不存在');
}

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'username') {
            $username = trim($_POST['username'] ?? '');

            if ($username === '') {
                throw new RuntimeException('用户名不能为空');
            }

            if (mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 32) {
                throw new RuntimeException('用户名长度应为 2-32 个字符');
            }

            $check = $pdo->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');
            $check->execute([$username, $uid]);

            if ($check->fetchColumn()) {
                throw new RuntimeException('这个用户名已经被使用');
            }

            $pdo->prepare('UPDATE users SET username=?, updated_at=NOW() WHERE id=?')->execute([$username, $uid]);

            $_SESSION['user']['username'] = $username;
            $msg = '用户名已更新';
            $user['username'] = $username;
        }

        if ($action === 'password') {
            $old = $_POST['old_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($new === '' || strlen($new) < 8) {
                throw new RuntimeException('新密码至少需要 8 位');
            }

            if ($new !== $confirm) {
                throw new RuntimeException('两次输入的新密码不一致');
            }

            $hash = $user['password_hash'] ?? '';

            if ($hash && !password_verify($old, $hash)) {
                throw new RuntimeException('当前密码不正确');
            }

            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([$newHash, $uid]);

            $msg = '密码已更新';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$title = '账号设置';
include __DIR__ . '/../app/layout/header.php';
?>

<div class="card">
    <p class="muted">这里可以修改用户名和密码。系统不需要邮箱字段。</p>

    <?php if ($msg): ?>
        <div class="alert"><?= e($msg) ?></div>
    <?php endif; ?>

    <?php if ($err): ?>
        <div class="alert danger"><?= e($err) ?></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>修改用户名</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="username">
        <div class="form-grid">
            <div>
                <label>用户名</label>
                <input name="username" value="<?= e($user['username'] ?? '') ?>" required>
            </div>
        </div>
        <p><button>保存用户名</button></p>
    </form>
</div>

<div class="card">
    <h2>修改密码</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <div class="form-grid">
            <div>
                <label>当前密码</label>
                <input type="password" name="old_password" required>
            </div>
            <div>
                <label>新密码</label>
                <input type="password" name="new_password" required>
            </div>
            <div>
                <label>确认新密码</label>
                <input type="password" name="confirm_password" required>
            </div>
        </div>
        <p><button>保存密码</button></p>
    </form>
</div>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
