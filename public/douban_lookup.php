<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/DoubanService.php';

header('Content-Type: application/json; charset=utf-8');
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '只允许 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!$token || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => '页面令牌已过期，请刷新后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $url = trim((string)($_POST['url'] ?? ''));
    $id = DoubanService::extractSubjectId($url);
    if (!$id) throw new RuntimeException('不是有效的豆瓣图书 URL');
    $book = DoubanService::fetchBookBySubjectId($id);
    echo json_encode(['ok' => true, 'book' => $book], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
