<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$uid = (int)$user['id'];
$pdo = db();

$registeredUsers = 0;
$recordedReadBooks = 0;
$myReadBooks = 0;
$myYearBooks = 0;
$recentRows = [];

try {
    $registeredUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $recordedReadBooks = (int)$pdo->query('SELECT COUNT(*) FROM reading_tasks')->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reading_tasks WHERE user_id = ?');
    $stmt->execute([$uid]);
    $myReadBooks = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM reading_tasks\n        WHERE user_id = ?\n          AND YEAR(COALESCE(actual_finish_date, planned_finish_date, created_at)) = YEAR(CURDATE())\n    ");
    $stmt->execute([$uid]);
    $myYearBooks = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("\n        SELECT\n            t.id AS task_id,\n            COALESCE(NULLIF(b.title, ''), NULLIF(t.raw_book_title, ''), '未命名书籍') AS book_title,\n            COALESCE(NULLIF(b.author, ''), NULLIF(t.raw_author, ''), '') AS author_name,\n            COALESCE(NULLIF(b.author_country, ''), NULLIF(t.task_author_country, ''), '') AS author_country,\n            COALESCE(t.actual_start_date, b.first_start_date, t.planned_start_date) AS start_date,\n            COALESCE(t.actual_finish_date, b.final_finish_date, t.planned_finish_date) AS finish_date,\n            COALESCE(t.rating_score, b.rating_score) AS score,\n            b.id AS book_id\n        FROM reading_tasks t\n        LEFT JOIN books b ON b.id = t.book_id\n        WHERE t.user_id = ?\n        ORDER BY COALESCE(t.actual_finish_date, b.final_finish_date, t.planned_finish_date, DATE(t.created_at)) DESC, t.id DESC\n        LIMIT 5\n    ");
    $stmt->execute([$uid]);
    $recentRows = $stmt->fetchAll();
} catch (Throwable $e) {
    // 首页仍可展示入口，避免某个统计查询导致整个站点不可访问。
}

$formatReadingPeriod = static function ($startDate, $finishDate): string {
    $startDate = trim((string)$startDate);
    $finishDate = trim((string)$finishDate);
    if ($startDate !== '' && $finishDate !== '') {
        return $startDate === $finishDate ? $startDate : $startDate . ' 至 ' . $finishDate;
    }
    if ($startDate !== '') return '开始于 ' . $startDate;
    if ($finishDate !== '') return '完成于 ' . $finishDate;
    return '';
};

$title = '首页';
include __DIR__ . '/../app/layout/header.php';
?>

<section class="yx-home-hero">
    <div>
        <p class="yx-eyebrow">欢迎回来</p>
        <h1><?= e($user['username'] ?? '读者') ?>，继续记录你的阅读。</h1>
        <p>把读过的书留下来，慢慢形成属于自己的阅读地图。</p>
    </div>
    <a class="btn yx-primary-action" href="record_create.php">＋ 录入已读书</a>
</section>

<section class="yx-public-overview" aria-label="平台概览">
    <article>
        <span>注册用户数</span>
        <strong><?= e(number_format($registeredUsers)) ?></strong>
        <small>正在使用阅心的读者</small>
    </article>
    <article>
        <span>录入已读书数</span>
        <strong><?= e(number_format($recordedReadBooks)) ?></strong>
        <small>全站累计阅读记录</small>
    </article>
</section>

<section class="yx-home-section">
    <div class="yx-section-heading">
        <div>
            <p class="yx-eyebrow">我的阅读</p>
            <h2>阅读概览</h2>
        </div>
        <a href="stats.php">查看统计</a>
    </div>

    <div class="yx-personal-overview">
        <a href="records.php">
            <span>我的已读</span>
            <strong><?= e(number_format($myReadBooks)) ?></strong>
            <small>全部阅读记录</small>
        </a>
        <a href="records.php?year=<?= e(date('Y')) ?>">
            <span>今年读完</span>
            <strong><?= e(number_format($myYearBooks)) ?></strong>
            <small><?= e(date('Y')) ?> 年</small>
        </a>
        <a href="plans.php">
            <span>阅读计划</span>
            <strong>→</strong>
            <small>查看当前目标</small>
        </a>
    </div>
</section>

<section class="yx-home-section">
    <div class="yx-section-heading">
        <div>
            <p class="yx-eyebrow">最近记录</p>
            <h2>刚刚读过</h2>
        </div>
        <a href="records.php">查看全部</a>
    </div>

    <?php if (!$recentRows): ?>
        <div class="yx-empty-state">
            <div aria-hidden="true">◇</div>
            <h3>还没有阅读记录</h3>
            <p>录入第一本读过的书，首页会在这里展示最近记录。</p>
            <a class="btn" href="record_create.php">录入第一本</a>
        </div>
    <?php else: ?>
        <div class="yx-recent-list">
            <?php foreach ($recentRows as $row): ?>
                <?php
                    $detailUrl = 'records.php?keyword=' . rawurlencode((string)$row['book_title']);
                    $readingPeriod = $formatReadingPeriod($row['start_date'] ?? '', $row['finish_date'] ?? '');
                    $authorSummary = trim((string)($row['author_name'] ?? '')) ?: '作者未录入';
                    if (trim((string)($row['author_country'] ?? '')) !== '') {
                        $authorSummary .= ' · ' . trim((string)$row['author_country']);
                    }
                ?>
                <a href="<?= e($detailUrl) ?>">
                    <div class="yx-recent-copy">
                        <strong><?= e($row['book_title']) ?></strong>
                        <span><?= e($authorSummary) ?></span>
                        <?php if ($readingPeriod !== ''): ?><small>阅读时间：<?= e($readingPeriod) ?></small><?php endif; ?>
                    </div>
                    <div class="yx-recent-meta">
                        <?php if (($row['score'] ?? '') !== ''): ?><span>★ <?= e($row['score']) ?></span><?php endif; ?>
                        <time><?= e($row['finish_date'] ?: '日期未录入') ?></time>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="yx-home-actions" aria-label="快捷功能">
    <a href="record_create.php"><span>＋</span><b>录入已读</b></a>
    <a href="plans.php"><span>□</span><b>阅读计划</b></a>
    <a href="import.php"><span>⇩</span><b>导入数据</b></a>
    <a href="stats.php"><span>⌁</span><b>阅读统计</b></a>
</section>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
