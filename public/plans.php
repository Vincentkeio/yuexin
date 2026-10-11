<?php
require_once __DIR__ . '/../app/bootstrap.php';

$u = require_login();
$uid = (int)$u['id'];

$where = ['user_id = ?', 'COALESCE(is_history_import,0) = 0'];
$args = [$uid];

if (($_GET['plan_type'] ?? '') !== '') {
    $where[] = 'plan_type = ?';
    $args[] = $_GET['plan_type'];
}

if (($_GET['status'] ?? '') !== '') {
    $where[] = 'status = ?';
    $args[] = $_GET['status'];
}

if (($kw = trim($_GET['keyword'] ?? '')) !== '') {
    $where[] = 'name LIKE ?';
    $args[] = "%{$kw}%";
}

$sql = '
    SELECT *
    FROM reading_plans
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY start_date DESC, id DESC
';

$stmt = db()->prepare($sql);
$stmt->execute($args);
$plans = $stmt->fetchAll();

$title = '阅读计划';
include __DIR__ . '/../app/layout/header.php';
?>

<div class="card">
    <p class="muted">这里只显示你主动创建的周度、月度、季度、年度阅读计划。CSV 导入产生的是阅读历史，不会进入阅读计划。</p>

    <form class="filters">
        <div>
            <label>关键词</label>
            <input name="keyword" value="<?= e($_GET['keyword'] ?? '') ?>">
        </div>
        <div>
            <label>计划类型</label>
            <select name="plan_type">
                <option value="">全部</option>
                <?php foreach (['weekly'=>'周度','monthly'=>'月度','quarterly'=>'季度','yearly'=>'年度'] as $k=>$v): ?>
                    <option value="<?= e($k) ?>" <?= ($_GET['plan_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>状态</label>
            <select name="status">
                <option value="">全部</option>
                <?php foreach (['not_started'=>'未开始','active'=>'进行中','completed'=>'已完成','failed'=>'未完成','cancelled'=>'已取消'] as $k=>$v): ?>
                    <option value="<?= e($k) ?>" <?= ($_GET['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><button>筛选</button></div>
    </form>
</div>

<div class="card actions">
    <a class="btn" href="plan_create.php">创建阅读计划</a>
    <a class="btn" href="records.php">阅读历史</a>
    <a class="btn" href="import_records.php">导入导出记录</a>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th>计划名称</th>
                <th>类型</th>
                <th>开始日期</th>
                <th>结束日期</th>
                <th>目标数量</th>
                <th>状态</th>
                <th>操作</th>
            </tr>
            <?php foreach ($plans as $p): ?>
                <tr>
                    <td><?= e($p['name']) ?></td>
                    <td><?= e($p['plan_type']) ?></td>
                    <td><?= e($p['start_date']) ?></td>
                    <td><?= e($p['end_date']) ?></td>
                    <td><?= e($p['target_count']) ?></td>
                    <td><?= e($p['status']) ?></td>
                    <td>
                        <a href="plan_detail.php?id=<?= e($p['id']) ?>">详情</a>
                        <a href="plan_edit.php?id=<?= e($p['id']) ?>">编辑</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
