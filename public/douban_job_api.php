<?php
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

try {
    $u = require_login();
    $uid = (int)$u['id'];
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => '登录状态已过期，请重新登录后再操作。'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'status';
$restart = in_array((string)($_POST['restart'] ?? $_GET['restart'] ?? ''), ['1', 'true', 'yes'], true);

// 查询状态可使用 GET；所有 POST（包括任务启动、中止、继续）必须带 CSRF。
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action !== 'status') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '该操作只允许 POST 请求。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!$token || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => '页面令牌已过期，请刷新页面后重试。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function yx_json(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function yx_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $col]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function yx_latest_job(PDO $pdo, int $uid): ?array {
    $stmt = $pdo->prepare("SELECT * FROM douban_jobs WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$uid]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    return $job ?: null;
}

function yx_payload(?array $job): array {
    if (!$job) {
        return [
            'ok' => true,
            'exists' => false,
            'running' => false,
            'paused' => false,
            'finished' => true,
            'status' => 'none',
            'total' => 0,
            'done' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'consecutive_failed' => 0,
            'current_title' => '',
            'message' => '',
            'last_error' => '',
            'notify' => false,
        ];
    }

    $status = (string)($job['status'] ?? 'none');
    $paused = in_array($status, ['paused', 'blocked', 'rate_limited', 'failed'], true);
    $finished = in_array($status, ['completed', 'cancelled'], true);

    return [
        'ok' => true,
        'exists' => true,
        'job_id' => (int)($job['id'] ?? 0),
        'running' => in_array($status, ['pending', 'running'], true),
        'paused' => $paused,
        'finished' => $finished,
        'status' => $status,
        'blocked' => $status === 'blocked',
        'rate_limited' => $status === 'rate_limited',
        'cancelled' => $status === 'cancelled',
        'total' => (int)($job['total'] ?? 0),
        'done' => (int)($job['done'] ?? 0),
        'success' => (int)($job['success'] ?? 0),
        'failed' => (int)($job['failed'] ?? 0),
        'skipped' => (int)($job['skipped'] ?? 0),
        'consecutive_failed' => (int)($job['consecutive_failed'] ?? 0),
        'current_title' => (string)($job['current_title'] ?? ''),
        'message' => (string)($job['message'] ?? ''),
        'last_error' => (string)($job['last_error'] ?? ''),
        'notify' => ($paused || $finished) && ((int)($job['notified'] ?? 0) === 0),
    ];
}

function yx_start_worker_fast(int $jobId): void {
    $root = dirname(__DIR__);
    $worker = $root . '/app/cli/douban_worker.php';
    $logDir = $root . '/storage/logs';
    $log = $logDir . '/douban_worker_' . $jobId . '.log';

    if (!is_file($worker) || !function_exists('shell_exec')) {
        return;
    }

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $php = PHP_BINARY ?: '/usr/bin/php';

    $cmd = 'cd ' . escapeshellarg($root)
        . ' && nohup ' . escapeshellarg($php)
        . ' ' . escapeshellarg($worker)
        . ' ' . (int)$jobId
        . ' < /dev/null >> ' . escapeshellarg($log)
        . ' 2>&1 & echo $!';

    @shell_exec($cmd);
}

function yx_stop_jobs(PDO $pdo, int $uid): void {
    // 先取得当前用户的任务 ID，避免使用全局 pkill 误杀其他用户的 worker。
    $idsStmt = $pdo->prepare("
        SELECT id FROM douban_jobs
        WHERE user_id=?
          AND status IN ('pending','running','processing','queued','paused','blocked','rate_limited','failed')
    ");
    $idsStmt->execute([$uid]);
    $jobIds = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare("
        UPDATE douban_jobs
        SET cancel_requested=1,
            status='cancelled',
            message='旧任务已被新的豆瓣任务替换。',
            finished_at=NOW(),
            updated_at=NOW()
        WHERE user_id=?
          AND status IN ('pending','running','processing','queued','paused','blocked','rate_limited','failed')
    ");
    $stmt->execute([$uid]);

    if (function_exists('shell_exec')) {
        foreach ($jobIds as $jobId) {
            $pattern = 'douban_worker\.php[[:space:]]+' . $jobId . '([[:space:]]|$)';
            @shell_exec('pkill -f ' . escapeshellarg($pattern) . ' 2>/dev/null || true');
        }
    }
}

function yx_create_job(PDO $pdo, int $uid): array {
    $where = ["user_id = ?"];

    /*
     * 第三方豆瓣 API 不一定返回评价人数/读过人数。
     * 不能因为 douban_rating_count / douban_read_count 为空就重复抓。
     *
     * 新规则：
     * 只处理“完全没有豆瓣标识和评分”的书。
     * 只要已有 douban_subject_id / douban_url / douban_rating / douban_title 任意一个，
     * 就认为已经获取过，不再重复进入新任务。
     */
    $doneSignals = [];
    foreach (['douban_subject_id', 'douban_url', 'douban_rating', 'douban_title'] as $c) {
        if (yx_col_exists($pdo, 'books', $c)) {
            $doneSignals[] = "`$c` IS NOT NULL AND `$c` <> ''";
        }
    }

    if ($doneSignals) {
        $where[] = 'NOT (' . implode(' OR ', $doneSignals) . ')';
    }

    $order = yx_col_exists($pdo, 'books', 'original_index')
        ? "CAST(COALESCE(NULLIF(original_index,''), id) AS UNSIGNED) ASC, id ASC"
        : "id ASC";

    $sql = "SELECT id FROM books WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT 800";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $status = count($ids) ? 'pending' : 'completed';
    $msg = count($ids) ? '豆瓣后台任务已创建。' : '暂无需要获取豆瓣信息的书籍。';

    $stmt = $pdo->prepare("
        INSERT INTO douban_jobs
        (user_id,status,total,done,success,failed,skipped,consecutive_failed,message,notified,cancel_requested,created_at,updated_at,finished_at)
        VALUES
        (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),?)
    ");
    $stmt->execute([
        $uid,
        $status,
        count($ids),
        0,
        0,
        0,
        0,
        0,
        $msg,
        0,
        0,
        count($ids) ? null : date('Y-m-d H:i:s')
    ]);

    $jobId = (int)$pdo->lastInsertId();

    if ($ids) {
        $ins = $pdo->prepare("
            INSERT IGNORE INTO douban_job_items
            (job_id,book_id,status,created_at,updated_at)
            VALUES
            (?,?,'pending',NOW(),NOW())
        ");
        foreach ($ids as $id) {
            $ins->execute([$jobId, $id]);
        }

        yx_start_worker_fast($jobId);
    }

    return yx_latest_job($pdo, $uid) ?: [];
}

try {
    if ($action === 'restart') {
        $action = 'start';
        $restart = true;
    }

    if ($action === 'status') {
        yx_json(yx_payload(yx_latest_job($pdo, $uid)));
    }

    if ($action === 'mark_notified') {
        $job = yx_latest_job($pdo, $uid);
        if ($job) {
            $stmt = $pdo->prepare("UPDATE douban_jobs SET notified=1 WHERE id=? AND user_id=?");
            $stmt->execute([(int)$job['id'], $uid]);
        }
        yx_json(['ok' => true]);
    }

    if ($action === 'cancel') {
        yx_stop_jobs($pdo, $uid);
        yx_json(yx_payload(yx_latest_job($pdo, $uid)));
    }

    if ($action === 'resume') {
        $job = yx_latest_job($pdo, $uid);
        if (!$job) {
            yx_json(yx_payload(null));
        }

        $jobId = (int)$job['id'];

        $pdo->prepare("
            UPDATE douban_job_items
            SET status='pending', updated_at=NOW()
            WHERE job_id=? AND status IN ('running','cancelled')
        ")->execute([$jobId]);

        $pdo->prepare("
            UPDATE douban_jobs
            SET status='pending',
                cancel_requested=0,
                notified=0,
                current_book_id=NULL,
                current_title=NULL,
                message='准备继续豆瓣后台任务。',
                last_error=NULL,
                finished_at=NULL,
                updated_at=NOW()
            WHERE id=? AND user_id=?
        ")->execute([$jobId, $uid]);

        yx_start_worker_fast($jobId);
        yx_json(yx_payload(yx_latest_job($pdo, $uid)));
    }

    if ($action === 'start') {
        if ($restart) {
            yx_stop_jobs($pdo, $uid);
            $newJob = yx_create_job($pdo, $uid);
            yx_json(yx_payload($newJob));
        }

        $job = yx_latest_job($pdo, $uid);

        if ($job && in_array((string)$job['status'], ['pending', 'running'], true)) {
            yx_start_worker_fast((int)$job['id']);
            yx_json(yx_payload(yx_latest_job($pdo, $uid)));
        }

        if ($job && in_array((string)$job['status'], ['paused', 'blocked', 'rate_limited', 'failed'], true)) {
            yx_json(yx_payload($job));
        }

        $newJob = yx_create_job($pdo, $uid);
        yx_json(yx_payload($newJob));
    }

    yx_json(['ok' => false, 'message' => '未知操作。']);
} catch (Throwable $e) {
    http_response_code(500);
    yx_json([
        'ok' => false,
        'message' => '豆瓣任务接口异常：' . $e->getMessage()
    ]);
}
