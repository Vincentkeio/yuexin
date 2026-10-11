<?php
require_once __DIR__ . '/../app/bootstrap.php';

$u = require_login();
$uid = (int)$u['id'];

$stmt = db()->prepare('
    SELECT b.*, p.name AS history_name
    FROM import_batches b
    LEFT JOIN reading_plans p ON p.id = b.history_plan_id
    WHERE b.user_id = ?
    ORDER BY b.id DESC
');
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

$title = '导入导出记录';
include __DIR__ . '/../app/layout/header.php';
?>

<div class="card">
    <p class="muted">这里仅记录 CSV 导入批次，不属于阅读计划。</p>
</div>

<div class="card actions">
    <a class="btn" href="import.php">导入 / 导出</a>
    <a class="btn" href="records.php">阅读历史</a>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th>ID</th>
                <th>文件名</th>
                <th>导入类型</th>
                <th>总行数</th>
                <th>成功</th>
                <th>失败</th>
                <th>历史名称</th>
                <th>时间</th>
            </tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['id']) ?></td>
                    <td><?= e($r['filename']) ?></td>
                    <td><?= e($r['import_type']) ?></td>
                    <td><?= e($r['total_rows']) ?></td>
                    <td><?= e($r['success_rows']) ?></td>
                    <td><?= e($r['failed_rows']) ?></td>
                    <td><?= e($r['history_name'] ?? '') ?></td>
                    <td><?= e($r['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
