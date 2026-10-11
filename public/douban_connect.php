<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/DoubanService.php';

$u = require_login();
$uid = (int)$u['id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('只允许 POST 请求');
}

verify_csrf();

$from = (string)($_POST['from'] ?? 'records');
if ($from === 'books') {
    $back = 'books.php';
} elseif ($from === 'records') {
    $back = 'records.php';
} elseif (preg_match('/^plan_detail\.php\?id=(\d+)$/', $from, $m)) {
    $back = 'plan_detail.php?id=' . (int)$m[1];
} else {
    $back = 'records.php';
}

try {
    if (($_POST['batch'] ?? '') === '1') {
        $stmt = $pdo->prepare('SELECT id FROM books WHERE user_id=? AND (douban_subject_id IS NULL OR douban_subject_id="") ORDER BY id DESC LIMIT 50');
        $stmt->execute([$uid]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $ok = 0;

        foreach ($ids as $id) {
            try {
                if (DoubanService::autoBindBookById((int)$id, $uid)) $ok++;
            } catch (Throwable $e) {}
        }

        $_SESSION['flash'] = '本次尝试连接 ' . count($ids) . ' 本，成功 ' . $ok . ' 本。';
        redirect($back);
    }

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) throw new RuntimeException('缺少书籍 ID');

    $ok = DoubanService::autoBindBookById($id, $uid);
    $_SESSION['flash'] = $ok ? '豆瓣连接成功' : '未能自动匹配豆瓣，可补充 ISBN/作者/出版社后再试。';
} catch (Throwable $e) {
    $_SESSION['flash'] = '豆瓣连接失败：' . $e->getMessage();
}

redirect($back);
