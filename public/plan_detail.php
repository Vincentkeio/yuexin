<?php
require_once __DIR__ . '/../app/bootstrap.php';

$u = require_login();
$uid = $u['id'];
$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM reading_plans WHERE id=? AND user_id=?');
$stmt->execute([$id, $uid]);
$plan = $stmt->fetch();

if (!$plan) die('计划不存在');

$st = $pdo->prepare('
    SELECT
        t.id task_id,t.raw_book_title,t.raw_author,t.history_name,t.task_original_index,t.task_media_text,t.task_format,t.task_platform,
        t.task_author_country,t.task_publisher,t.task_publish_year,t.task_translator,t.task_is_full,
        t.planned_start_date,t.planned_finish_date,t.actual_start_date,t.actual_finish_date,
        t.status task_status,t.progress task_progress,t.continue_next,t.rating_score task_rating_score,t.rating_level task_rating_level,t.comment,
        b.*
    FROM reading_tasks t
    LEFT JOIN books b ON b.id=t.book_id
    WHERE t.plan_id=? AND t.user_id=?
    ORDER BY t.id ASC
');
$st->execute([$id, $uid]);
$rows = $st->fetchAll();

$title = '计划详情';
include __DIR__ . '/../app/layout/header.php';
?>
<div class="card">
    <h2><?= e($plan['name']) ?></h2>
    <p class="muted">
        周期：<?= e($plan['start_date']) ?> 至 <?= e($plan['end_date']) ?>
        目标：<?= e($plan['target_count']) ?> 本
        状态：<?= e($plan['status']) ?>
    </p>
</div>

<div class="card actions">
    <a class="btn" href="records.php">阅读历史</a>
    <a class="btn" href="export.php?type=records">导出阅读历史 CSV</a>
</div>

<div class="card">
    <h2>计划 / 阅读历史明细</h2>
    <div class="table-wrap">
        <table>
            <tr>
                <th>序号</th><th>书名</th><th>作者</th><th>国籍</th><th>译者</th><th>出版社</th><th>出版年</th><th>ISBN</th>
                <th>载体</th><th>阅读形式</th><th>阅读平台</th><th>是否全本</th>
                <th>计划开始阅读日期</th><th>计划完成阅读日期</th><th>实际开始阅读日期</th><th>实际完成阅读日期</th>
                <th>历史状态</th><th>历史进度</th><th>自我评分</th><th>豆瓣评分</th><th>豆瓣阅读人数</th><th>备注</th><th>操作</th>
            </tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['original_index'] ?? $r['task_original_index'] ?? '') ?></td>
                    <td><?= e($r['title'] ?? $r['raw_book_title'] ?? '') ?></td>
                    <td><?= e($r['author'] ?? $r['raw_author'] ?? '') ?></td>
                    <td><?= e($r['author_country'] ?? $r['task_author_country'] ?? '') ?></td>
                    <td><?= e($r['translator'] ?? $r['task_translator'] ?? '') ?></td>
                    <td><?= e($r['publisher'] ?? $r['task_publisher'] ?? '') ?></td>
                    <td><?= e($r['publish_year'] ?? $r['task_publish_year'] ?? '') ?></td>
                    <td><?= e($r['isbn'] ?? '') ?></td>
                    <td><?= e($r['media_text'] ?? $r['task_media_text'] ?? '') ?></td>
                    <td><?= e($r['format'] ?? $r['task_format'] ?? '') ?></td>
                    <td><?= e($r['platform'] ?? $r['task_platform'] ?? '') ?></td>
                    <td><?= e($r['is_full'] ?? $r['task_is_full'] ?? '') ?></td>
                    <td><?= e($r['planned_start_date'] ?? '') ?></td>
                    <td><?= e($r['planned_finish_date'] ?? '') ?></td>
                    <td><?= e($r['actual_start_date'] ?? '') ?></td>
                    <td><?= e($r['actual_finish_date'] ?? '') ?></td>
                    <td><?= e($r['task_status'] ?? '') ?></td>
                    <td><?= e($r['task_progress'] ?? '') ?></td>
                    <td><?= e($r['rating_score'] ?? $r['task_rating_score'] ?? '') ?></td>
                    <td><?= e($r['douban_rating'] ?? '') ?></td>
                    <td><?= e($r['douban_read_count'] ?? '') ?></td>
                    <td><?= e($r['comment'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($r['id'])): ?>
                            <a href="book_detail.php?id=<?= e($r['id']) ?>">详情</a>
                            <form method="post" action="douban_connect.php" class="yx-inline-action">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                                <input type="hidden" name="from" value="plan_detail.php?id=<?= e($id) ?>">
                                <button type="submit" class="yx-link-button">连接豆瓣</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../app/layout/footer.php'; ?>
