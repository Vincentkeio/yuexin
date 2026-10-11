<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/DoubanService.php';

$jobId = (int)($argv[1] ?? 0);
if ($jobId <= 0) {
    exit("缺少 job_id\n");
}

$pdo = db();

function yx_job_update(PDO $pdo, int $jobId, array $data): void
{
    $sets = [];
    $params = [];

    foreach ($data as $k => $v) {
        $sets[] = "`$k` = ?";
        $params[] = $v;
    }

    if (!$sets) return;

    $params[] = $jobId;
    $pdo->prepare("UPDATE douban_jobs SET " . implode(',', $sets) . ", updated_at=NOW() WHERE id=?")->execute($params);
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

function yx_pause_message(): string
{
    return '豆瓣限制了当前访问出口，任务已暂停。请稍后再试，或手动处理 WARP 后点击“继续任务”。';
}

$stmt = $pdo->prepare("SELECT * FROM douban_jobs WHERE id=? LIMIT 1");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    exit("任务不存在\n");
}

$userId = (int)$job['user_id'];

yx_job_update($pdo, $jobId, [
    'status' => 'running',
    'started_at' => $job['started_at'] ?: date('Y-m-d H:i:s'),
    'message' => '豆瓣后台任务已开始。',
    'cancel_requested' => 0,
]);

while (true) {
    $stmt = $pdo->prepare("SELECT * FROM douban_jobs WHERE id=? LIMIT 1");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) exit;

    if ((int)$job['cancel_requested'] === 1) {
        yx_job_update($pdo, $jobId, [
            'status' => 'cancelled',
            'message' => '豆瓣信息获取已中止。',
            'current_book_id' => null,
            'current_title' => null,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT i.book_id, b.title
        FROM douban_job_items i
        JOIN books b ON b.id = i.book_id
        WHERE i.job_id = ?
          AND i.status IN ('pending','running')
        ORDER BY
            CASE WHEN i.status='running' THEN 0 ELSE 1 END,
            CAST(COALESCE(NULLIF(b.original_index,''), b.id) AS UNSIGNED) ASC,
            i.id ASC
        LIMIT 1
    ");
    $stmt->execute([$jobId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        yx_job_update($pdo, $jobId, [
            'status' => 'completed',
            'message' => '豆瓣信息获取完成。',
            'current_book_id' => null,
            'current_title' => null,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        exit;
    }

    $bookId = (int)$item['book_id'];
    $title = (string)$item['title'];

    yx_job_update($pdo, $jobId, [
        'current_book_id' => $bookId,
        'current_title' => $title,
        'message' => '正在处理：《' . $title . '》',
    ]);

    $pdo->prepare("UPDATE douban_job_items SET status='running', updated_at=NOW() WHERE job_id=? AND book_id=?")
        ->execute([$jobId, $bookId]);

    try {
        $ok = DoubanService::autoBindBookById($bookId, $userId);

        if ($ok) {
            $pdo->prepare("UPDATE douban_job_items SET status='success', error=NULL, updated_at=NOW() WHERE job_id=? AND book_id=?")
                ->execute([$jobId, $bookId]);

            $pdo->prepare("
                UPDATE douban_jobs
                SET done=done+1,
                    success=success+1,
                    consecutive_failed=0,
                    last_error=NULL,
                    message=?,
                    updated_at=NOW()
                WHERE id=?
            ")->execute(['已获取：《' . $title . '》', $jobId]);
        } else {
            $stmt = $pdo->prepare("SELECT douban_last_error, douban_match_status FROM books WHERE id=? AND user_id=? LIMIT 1");
            $stmt->execute([$bookId, $userId]);
            $bookErr = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $err = (string)($bookErr['douban_last_error'] ?? '');
            $status = (string)($bookErr['douban_match_status'] ?? '');

            if ($err === '') $err = '未找到合适豆瓣书籍';

            if ($status === 'blocked' || yx_is_block_error($err)) {
                $msg = yx_pause_message();

                $pdo->prepare("UPDATE douban_job_items SET status='pending', error=?, updated_at=NOW() WHERE job_id=? AND book_id=?")
                    ->execute([$msg, $jobId, $bookId]);

                yx_job_update($pdo, $jobId, [
                    'status' => 'paused',
                    'last_error' => $msg,
                    'message' => $msg,
                    'current_book_id' => null,
                    'current_title' => null,
                    'finished_at' => null,
                ]);

                exit;
            }

            $pdo->prepare("UPDATE douban_job_items SET status='failed', error=?, updated_at=NOW() WHERE job_id=? AND book_id=?")
                ->execute([$err, $jobId, $bookId]);

            $pdo->prepare("
                UPDATE douban_jobs
                SET done=done+1,
                    failed=failed+1,
                    consecutive_failed=consecutive_failed+1,
                    last_error=?,
                    message=?,
                    updated_at=NOW()
                WHERE id=?
            ")->execute([$err, '未找到：《' . $title . '》', $jobId]);
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();

        if (yx_is_block_error($err)) {
            $msg = yx_pause_message();

            $pdo->prepare("UPDATE douban_job_items SET status='pending', error=?, updated_at=NOW() WHERE job_id=? AND book_id=?")
                ->execute([$msg, $jobId, $bookId]);

            yx_job_update($pdo, $jobId, [
                'status' => 'paused',
                'last_error' => $msg,
                'message' => $msg,
                'current_book_id' => null,
                'current_title' => null,
                'finished_at' => null,
            ]);

            exit;
        }

        $pdo->prepare("UPDATE douban_job_items SET status='failed', error=?, updated_at=NOW() WHERE job_id=? AND book_id=?")
            ->execute([$err, $jobId, $bookId]);

        $pdo->prepare("
            UPDATE douban_jobs
            SET done=done+1,
                failed=failed+1,
                consecutive_failed=consecutive_failed+1,
                last_error=?,
                message=?,
                updated_at=NOW()
            WHERE id=?
        ")->execute([$err, '处理失败：《' . $title . '》', $jobId]);
    }
    // 本地第三方豆瓣 API 快速模式：
    // 原来为防豆瓣封禁每本等待 8~15 秒；现在默认只等 150ms。
    
    // 连续失败保护：第三方豆瓣 API / 豆瓣上游异常时，不继续把后续书全部刷成失败
    try {
        $chk = $pdo->prepare("SELECT consecutive_failed FROM douban_jobs WHERE id=? LIMIT 1");
        $chk->execute([$jobId]);
        $cf = (int)$chk->fetchColumn();

        if ($cf >= 5) {
            $pdo->prepare("
                UPDATE douban_jobs
                SET status='paused',
                    message='连续失败过多，任务已自动暂停。可能是第三方豆瓣 API 或豆瓣上游暂时限流，请稍后继续。',
                    updated_at=NOW()
                WHERE id=?
            ")->execute([$jobId]);
            break;
        }
    } catch (Throwable $e) {}

    $delayMs = (int)(getenv('DOUBAN_WORKER_DELAY_MS') ?: 500);
    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }
}
