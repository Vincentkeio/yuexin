<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$uid = $user['id'];
$pdo = db();

$where = ['user_id=?'];
$args = [$uid];

foreach (['status','format','author_country','rating_level'] as $f) {
    if (($_GET[$f] ?? '') !== '') {
        $where[] = "$f=?";
        $args[] = $_GET[$f];
    }
}

if (($kw = trim($_GET['keyword'] ?? '')) !== '') {
    $where[] = '(title LIKE ? OR author LIKE ? OR publisher LIKE ? OR author_country LIKE ? OR media_text LIKE ?)';
    array_push($args, "%$kw%", "%$kw%", "%$kw%", "%$kw%", "%$kw%");
}

if (($_GET['douban_bound'] ?? '') === '1') {
    $where[] = 'douban_subject_id IS NOT NULL';
}

if (($_GET['douban_bound'] ?? '') === '0') {
    $where[] = 'douban_subject_id IS NULL';
}

$sorts = [
    'created_desc' => 'created_at DESC',
    'finish_desc' => 'final_finish_date DESC',
    'start_desc' => 'first_start_date DESC',
    'score_desc' => 'rating_score DESC',
    'douban_desc' => 'douban_rating DESC',
    'progress_desc' => 'progress DESC',
    'title_asc' => 'title ASC'
];

$sort = $sorts[$_GET['sort'] ?? 'created_desc'] ?? 'created_at DESC';

$sql = 'SELECT * FROM books WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $sort . ' LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$books = $stmt->fetchAll();

$title = '我的书库';
include __DIR__ . '/../app/layout/header.php';
?>
<div class="card">
    <form class="filters">
        <div>
            <label>关键词</label>
            <input name="keyword" value="<?= e($_GET['keyword'] ?? '') ?>">
        </div>
        <div>
            <label>状态</label>
            <select name="status">
                <option value="">全部</option>
                <?php foreach (['unread'=>'未读','reading'=>'在读','finished'=>'已读','unfinished'=>'未完成','abandoned'=>'弃读'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= ($_GET['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>形式</label>
            <select name="format">
                <option value="">全部</option>
                <?php foreach (['paper'=>'纸质书','ebook'=>'电子书','audiobook'=>'有声书','unknown'=>'未选择'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= ($_GET['format'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>作者国籍</label>
            <input name="author_country" value="<?= e($_GET['author_country'] ?? '') ?>">
        </div>
        <div>
            <label>豆瓣</label>
            <select name="douban_bound">
                <option value="">全部</option>
                <option value="1" <?= ($_GET['douban_bound'] ?? '') === '1' ? 'selected' : '' ?>>已连接</option>
                <option value="0" <?= ($_GET['douban_bound'] ?? '') === '0' ? 'selected' : '' ?>>未连接</option>
            </select>
        </div>
        <div>
            <button>筛选</button>
        </div>
    </form>
</div>

<div class="card actions">
    <a class="btn yx-add-book-link" href="/book_create.php">添加书籍</a>
    <a class="btn" href="export.php?type=books">导出标准 CSV</a>
    <form method="post" action="douban_connect.php" class="yx-action-form">
        <?= csrf_field() ?>
        <input type="hidden" name="batch" value="1">
        <input type="hidden" name="from" value="books">
        <button type="submit" class="btn">一键连接豆瓣</button>
    </form>
    <a class="btn" href="dedupe_books.php">合并去重</a>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th>书名</th>
                <th>作者</th>
                <th>国籍</th>
                <th>出版社</th>
                <th>出版年</th>
                <th>译者</th>
                <th>载体</th>
                <th>形式</th>
                <th>平台</th>
                <th>状态</th>
                <th>进度</th>
                <th>开始</th>
                <th>读完</th>
                <th>我的评分</th>
                <th>豆瓣评分</th>
                <th>评价人数</th>
                <th>读过人数</th>
                <th>操作</th>
            </tr>
            <?php foreach ($books as $b): ?>
                <tr>
                    <td><a href="book_detail.php?id=<?= $b['id'] ?>"><?= e($b['title']) ?></a></td>
                    <td><?= e($b['author']) ?></td>
                    <td><?= e($b['author_country']) ?></td>
                    <td><?= e($b['publisher']) ?></td>
                    <td><?= e($b['publish_year'] ?: $b['publish_date']) ?></td>
                    <td><?= e($b['translator']) ?></td>
                    <td><?= e($b['media_text']) ?></td>
                    <td><?= format_name($b['format']) ?></td>
                    <td><?= e($b['platform']) ?></td>
                    <td><span class="badge"><?= badge_status($b['status']) ?></span></td>
                    <td><?= e($b['progress']) ?>%</td>
                    <td><?= e($b['first_start_date']) ?></td>
                    <td><?= e($b['final_finish_date']) ?></td>
                    <td><?= e($b['rating_score']) ?> <?= rating_level_name($b['rating_level']) ?></td>
                    <td><?= e($b['douban_rating']) ?></td>
                    <td><?= e($b['douban_rating_count']) ?></td>
                    <td><?= e($b['douban_read_count']) ?></td>
                    <td class="actions">
                        <a href="book_edit.php?id=<?= $b['id'] ?>">编辑</a>
                        <form method="post" action="douban_connect.php" class="yx-inline-action">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= e($b['id']) ?>">
                            <input type="hidden" name="from" value="books">
                            <button type="submit" class="yx-link-button">连接豆瓣</button>
                        </form>
                        <form method="post" action="book_delete.php" class="yx-inline-delete" data-confirm-form="确定删除？">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= e($b['id']) ?>">
                            <button type="submit" class="yx-link-button">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../app/layout/footer.php'; ?>
