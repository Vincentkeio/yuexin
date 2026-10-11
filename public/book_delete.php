<?php
require_once __DIR__ . '/../app/bootstrap.php';

$u = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('只允许 POST 请求');
}

verify_csrf();
$id = (int)($_POST['id'] ?? 0);

db()->prepare('DELETE FROM books WHERE id=? AND user_id=?')->execute([$id, $u['id']]);
$_SESSION['flash'] = '书籍已删除';
redirect('books.php');
