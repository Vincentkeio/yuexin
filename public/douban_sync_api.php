<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/DoubanService.php';

header('Content-Type: application/json; charset=utf-8');

$u = require_login();
$uid = (int)$u['id'];
$pdo = db();

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

$action = $_POST['action'] ?? 'step';

function yx_output_state(array $state, bool $finished = false, ?string $currentTitle = null): void
{
    echo json_encode([
        'finished' => $finished,
        'cancelled' => !empty($state['cancelled']),
        'blocked' => !empty($state['blocked']),
        'rate_limited' => !empty($state['rate_limited']),
        'total' => $state['total'] ?? 0,
        'done' => $state['done'] ?? 0,
        'success' => $state['success'] ?? 0,
        'failed' => $state['failed'] ?? 0,
        'skipped' => $state['skipped'] ?? 0,
        'consecutive_failed' => $state['consecutive_failed'] ?? 0,
        'current_title' => $currentTitle,
        'last_error' => $state['last_error'] ?? '',
        'message' => $state['message'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function yx_is_block_error(string $msg): bool
{
    return str_contains($msg, 'DOUBAN_BLOCKED')
        || str_contains($msg, 'sec.douban')
        || str_contains($msg, '403')
        || str_contains($msg, '异常请求')
        || str_contains($msg, '请 登录 使用豆瓣')
        || str_contains($msg, '豆瓣限制')
        || str_contains($msg, '登录跳转');
}

function yx_block_message(): string
{
    return '豆瓣限制了当前服务器 IP，已自动中止本次获取任务。请稍后再试，或使用豆瓣信息 CSV 导入。';
}

function yx_rate_limit_message(int $n): string
{
    return "已连续失败 {$n} 本，系统判断可能触发豆瓣风控或网络异常，已自动暂停。建议稍后再继续，避免继续刷失败。";
}

if ($action === 'cancel') {
    if (!isset($_SESSION['douban_sync'])) {
        $_SESSION['douban_sync'] = [
            'ids' => [],
            'total' => 0,
            'done' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'consecutive_failed' => 0,
            'last_error' => '',
            'message' => '已中止本次豆瓣信息获取。',
            'cancelled' => true,
            'blocked' => false,
            'rate_limited' => false,
        ];
    } else {
        $_SESSION['douban_sync']['cancelled'] = true;
        $_SESSION['douban_sync']['ids'] = [];
        $_SESSION['douban_sync']['message'] = '已中止本次豆瓣信息获取。';
    }

    yx_output_state($_SESSION['douban_sync'], true);
}

if ($action === 'start' || !isset($_SESSION['douban_sync'])) {
    $stmt = $pdo->prepare('
        SELECT id
        FROM books
        WHERE user_id = ?
          AND (
              douban_rating IS NULL
              OR douban_read_count IS NULL
              OR douban_url IS NULL
              OR douban_url = ""
              OR douban_subject_id IS NULL
              OR douban_subject_id = ""
          )
        ORDER BY CAST(COALESCE(NULLIF(original_index,""), id) AS UNSIGNED) ASC, id ASC
        LIMIT 800
    ');
    $stmt->execute([$uid]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $_SESSION['douban_sync'] = [
        'ids' => $ids,
        'total' => count($ids),
        'done' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'consecutive_failed' => 0,
        'last_error' => '',
        'message' => count($ids) ? '开始获取豆瓣信息。' : '暂无需要获取豆瓣信息的书籍。',
        'cancelled' => false,
        'blocked' => false,
        'rate_limited' => false,
    ];

    yx_output_state($_SESSION['douban_sync'], count($ids) === 0);
}

$state = &$_SESSION['douban_sync'];

if (!empty($state['cancelled']) || !empty($state['blocked']) || !empty($state['rate_limited'])) {
    $state['ids'] = [];
    yx_output_state($state, true);
}

if (empty($state['ids'])) {
    $state['message'] = '豆瓣信息获取完成。';
    yx_output_state($state, true);
}

$id = array_shift($state['ids']);

$stmt = $pdo->prepare('SELECT title FROM books WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$id, $uid]);
$title = (string)$stmt->fetchColumn();

try {
    $ok = DoubanService::autoBindBookById($id, $uid);

    if ($ok) {
        $state['success']++;
        $state['consecutive_failed'] = 0;
        $state['last_error'] = '';
        $state['message'] = '已获取豆瓣信息。';
    } else {
        $state['failed']++;
        $state['consecutive_failed']++;

        $stmt = $pdo->prepare('SELECT douban_last_error, douban_match_status FROM books WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$id, $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $err = (string)($row['douban_last_error'] ?? '');
        $status = (string)($row['douban_match_status'] ?? '');

        if ($status === 'blocked' || yx_is_block_error($err)) {
            $state['blocked'] = true;
            $state['cancelled'] = true;
            $state['ids'] = [];
            $state['last_error'] = yx_block_message();
            $state['message'] = yx_block_message();

            $state['done']++;
            yx_output_state($state, true, $title);
        }

        if ((int)$state['consecutive_failed'] >= 8) {
            $state['rate_limited'] = true;
            $state['cancelled'] = true;
            $state['ids'] = [];
            $state['last_error'] = $err ?: '连续失败次数过多';
            $state['message'] = yx_rate_limit_message((int)$state['consecutive_failed']);

            $state['done']++;
            yx_output_state($state, true, $title);
        }

        $state['last_error'] = $err ?: '未找到合适豆瓣书籍。';
        $state['message'] = '未找到合适豆瓣书籍，继续处理下一本。';
    }
} catch (Throwable $e) {
    $msg = $e->getMessage();

    if (yx_is_block_error($msg)) {
        $state['blocked'] = true;
        $state['cancelled'] = true;
        $state['ids'] = [];
        $state['failed']++;
        $state['last_error'] = yx_block_message();
        $state['message'] = yx_block_message();

        try {
            $pdo->prepare('
                UPDATE books
                SET douban_match_status="blocked",
                    douban_last_error=?,
                    douban_last_try_at=NOW()
                WHERE id=? AND user_id=?
            ')->execute([$state['last_error'], $id, $uid]);
        } catch (Throwable $ignored) {}

        $state['done']++;
        yx_output_state($state, true, $title);
    }

    $state['failed']++;
    $state['consecutive_failed']++;
    $state['last_error'] = $msg;

    if ((int)$state['consecutive_failed'] >= 8) {
        $state['rate_limited'] = true;
        $state['cancelled'] = true;
        $state['ids'] = [];
        $state['message'] = yx_rate_limit_message((int)$state['consecutive_failed']);

        $state['done']++;
        yx_output_state($state, true, $title);
    }

    $state['message'] = '处理未完成，继续处理下一本。';
}

$state['done']++;

yx_output_state($state, count($state['ids']) === 0, $title);
