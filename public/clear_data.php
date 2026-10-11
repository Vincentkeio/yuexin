<?php
require_once __DIR__ . '/../app/bootstrap.php';

$u = require_login();
$uid = $u['id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (trim($_POST['confirm'] ?? '') !== '清空') {
        $_SESSION['flash'] = '确认文字不正确，未清空。';
        redirect('clear_data.php');
    }

    $pdo->beginTransaction();

    try {
        $pdo->prepare('DELETE FROM reading_tasks WHERE user_id=?')->execute([$uid]);
        $pdo->prepare('DELETE FROM reading_plans WHERE user_id=?')->execute([$uid]);
        $pdo->prepare('DELETE FROM import_batches WHERE user_id=?')->execute([$uid]);
        $pdo->prepare('DELETE FROM books WHERE user_id=?')->execute([$uid]);
        $pdo->commit();

        $_SESSION['flash'] = '当前账号的书库、阅读历史、阅读计划和导入记录已清空。';
        redirect('dashboard.php');
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = '清空失败：' . $e->getMessage();
        redirect('clear_data.php');
    }
}

$title = '一键清空';
include __DIR__ . '/../app/layout/header.php';
?>
<div class="card">
    <p class="muted">只清空当前登录账号的数据，不会删除用户账号。</p>
    <p class="muted">将删除：书库、阅读历史、阅读计划、导入批次。</p>
    <form method="post">
        <?= csrf_field() ?>
        <label>输入“清空”确认</label>
        <input name="confirm" placeholder="清空">
        <p>
            <button class="danger">确认清空</button>
            <a class="btn ghost" href="import.php">返回</a>
        </p>
    </form>
</div>
<?php include __DIR__ . '/../app/layout/footer.php'; ?>
