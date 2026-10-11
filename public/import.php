<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/services/ImportExportService.php';
require_once __DIR__ . '/../app/services/BookService.php';
require_once __DIR__ . '/../app/services/DoubanService.php';

$u = require_login();
$uid = $u['id'];
$pdo = db();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        if (empty($_FILES['file']['tmp_name'])) {
            throw new RuntimeException('请选择 CSV 文件');
        }

        $rows = ImportExportService::readUploaded($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        $items = ImportExportService::normalizeRows($rows);
        $mode = $_POST['mode'] ?? 'history';
        $autoDouban = ($_POST['auto_douban'] ?? '1') === '1';

        $success = 0;
        $failed = 0;
        $doubanOk = 0;
        $historyPlanId = null;

        if ($mode === 'history') {
            $name = trim($_POST['history_plan_name'] ?? '历史导入记录') ?: '历史导入记录';
            $start = $_POST['history_start'] ?: date('Y-01-01');
            $end = $_POST['history_end'] ?: date('Y-12-31');

            $pdo->prepare('
                INSERT INTO reading_plans(user_id,name,plan_type,start_date,end_date,target_count,description,status,is_history_import)
                VALUES(?,?,?,?,?,?,?,?,?)
            ')->execute([
                $uid,$name,'yearly',$start,$end,0,'CSV 标准格式导入生成的阅读历史','completed',1
            ]);

            $historyPlanId = (int)$pdo->lastInsertId();
        }

        foreach ($items as $it) {
            try {
                $row = ImportExportService::mapRow($it);

                if (trim($row['title']) === '') {
                    throw new RuntimeException('书名为空');
                }

                if (!empty($row['douban_subject_id'])) {
                    try {
                        $douban = DoubanService::fetchBookBySubjectId($row['douban_subject_id']);
                        $row = array_merge($row, array_filter($douban, fn($v) => $v !== null && $v !== ''));
                    } catch (Throwable $e) {}
                }

                $bookId = BookService::createFromRow($row, $uid);

                if ($autoDouban && empty($row['douban_subject_id'])) {
                    try {
                        if (DoubanService::autoBindBookById($bookId, $uid)) $doubanOk++;
                    } catch (Throwable $e) {}
                }

                if (!empty($row['douban_subject_id'])) $doubanOk++;

                if ($mode === 'history') {
                    $pdo->prepare('
                        INSERT INTO reading_tasks(
                            user_id,plan_id,book_id,raw_book_title,raw_author,history_name,
                            task_original_index,task_media_text,task_format,task_platform,task_author_country,task_publisher,task_publish_year,task_translator,task_is_full,
                            planned_start_date,planned_finish_date,actual_start_date,actual_finish_date,
                            status,progress,continue_next,rating_score,rating_level,comment
                        )
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ')->execute([
                        $uid,$historyPlanId,$bookId,$row['title'],$row['author'],$row['_history_name'],
                        $row['original_index'],$row['media_text'],$row['format'],$row['platform'],$row['author_country'],$row['publisher'],$row['publish_year'],$row['translator'],$row['is_full'],
                        $row['_planned_start'] ?: $row['first_start_date'],
                        $row['_planned_finish'] ?: $row['final_finish_date'],
                        $row['_actual_start'] ?: $row['first_start_date'],
                        $row['_actual_finish'] ?: $row['final_finish_date'],
                        $row['_task_status'] ?: ($row['status'] === 'finished' ? 'completed' : ($row['status'] === 'reading' ? 'reading' : $row['status'])),
                        $row['_task_progress'] ?: $row['progress'],
                        $row['_continue_next'],
                        ($row['_task_rating_score'] ?? null) ?: ($row['rating_score'] ?? null),
                        ($row['_task_rating_level'] ?? null) ?: ($row['rating_level'] ?? null),
                        $row['_task_comment'] ?: $row['short_review']
                    ]);
                }

                $success++;
            } catch (Throwable $e) {
                $failed++;
            }
        }

        $pdo->prepare('
            INSERT INTO import_batches(user_id,filename,import_type,total_rows,success_rows,failed_rows,history_plan_id)
            VALUES(?,?,?,?,?,?,?)
        ')->execute([$uid,$_FILES['file']['name'],$mode,count($items),$success,$failed,$historyPlanId]);

        $result = ['success'=>$success,'failed'=>$failed,'douban'=>$doubanOk,'plan_id'=>$historyPlanId];
    } catch (Throwable $e) {
        $result = ['error'=>$e->getMessage()];
    }
}

$title = '导入导出';
include __DIR__ . '/../app/layout/header.php';
?>
<div class="card">
    <h2>标准 CSV 导入</h2>
    <p class="muted">仅支持 CSV。字段为空也会保留列。导入时可自动尝试连接豆瓣。</p>

    <?php if ($result): ?>
        <div class="alert">
            <?php if (isset($result['error'])): ?>
                <?= e($result['error']) ?>
            <?php else: ?>
                导入完成：成功 <?= e($result['success']) ?> 行，失败 <?= e($result['failed']) ?> 行，豆瓣连接 <?= e($result['douban']) ?> 条。
                <?php if ($result['plan_id']): ?><a href="plan_detail.php?id=<?= e($result['plan_id']) ?>">查看历史</a><?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div><label>导入文件</label><input type="file" name="file" accept=".csv" required></div>
            <div>
                <label>导入模式</label>
                <select name="mode">
                    <option value="books">只导入书库</option>
                    <option value="history" selected>导入书库并形成阅读历史</option>
                </select>
            </div>
            <div><label>历史名称</label><input name="history_plan_name" value="历史导入记录"></div>
            <div><label>历史开始</label><input type="date" name="history_start" value="<?= date('Y-01-01') ?>"></div>
            <div><label>历史结束</label><input type="date" name="history_end" value="<?= date('Y-12-31') ?>"></div>
            <div>
                <label>豆瓣</label>
                <select name="auto_douban">
                    <option value="1" selected>导入时自动尝试连接豆瓣</option>
                    <option value="0">暂不连接豆瓣</option>
                </select>
            </div>
        </div>
        <p>
            <button>开始导入</button>
            <a class="btn" href="export.php?type=template">下载标准 CSV 模板</a>
            <a class="btn" href="export.php?type=records">导出阅读历史标准 CSV</a>
            <a class="btn ghost" href="clear_data.php">一键清空我的数据</a>
            <a class="btn" href="import_records.php">导入导出记录</a>
            <a class="btn" href="dedupe_books.php">合并去重</a>
        </p>
    </form>
</div>
<?php include __DIR__ . '/../app/layout/footer.php'; ?>
