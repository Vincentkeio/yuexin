<?php
require_once __DIR__ . '/../app/bootstrap.php';

$u = require_login();
$uid = (int)$u['id'];
$pdo = db();

function one($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function rows($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$totalBooks = (int)one('SELECT COUNT(*) FROM books WHERE user_id=?', [$uid]);

$totalHistory = (int)one('SELECT COUNT(*) FROM reading_tasks WHERE user_id=?', [$uid]);

$completedHistory = (int)one('
    SELECT COUNT(*)
    FROM reading_tasks
    WHERE user_id=?
', [$uid]);

$withDouban = (int)one('
    SELECT COUNT(*)
    FROM books
    WHERE user_id=?
      AND (
        douban_rating IS NOT NULL
        OR douban_read_count IS NOT NULL
        OR douban_url IS NOT NULL
      )
', [$uid]);

$avgSelf = one('
    SELECT ROUND(AVG(rating_score), 2)
    FROM books
    WHERE user_id=?
      AND rating_score IS NOT NULL
', [$uid]);

$avgDouban = one('
    SELECT ROUND(AVG(douban_rating), 2)
    FROM books
    WHERE user_id=?
      AND douban_rating IS NOT NULL
', [$uid]);

$plans = (int)one('
    SELECT COUNT(*)
    FROM reading_plans
    WHERE user_id=?
      AND COALESCE(is_history_import,0)=0
', [$uid]);

$planTarget = (int)one('
    SELECT COALESCE(SUM(target_count),0)
    FROM reading_plans
    WHERE user_id=?
      AND COALESCE(is_history_import,0)=0
', [$uid]);

$byCountry = rows('
    SELECT COALESCE(NULLIF(author_country,""), "未填写") AS name, COUNT(*) AS c
    FROM books
    WHERE user_id=?
    GROUP BY COALESCE(NULLIF(author_country,""), "未填写")
    ORDER BY c DESC
    LIMIT 12
', [$uid]);

$byPublisher = rows('
    SELECT COALESCE(NULLIF(publisher,""), "未填写") AS name, COUNT(*) AS c
    FROM books
    WHERE user_id=?
    GROUP BY COALESCE(NULLIF(publisher,""), "未填写")
    ORDER BY c DESC
    LIMIT 12
', [$uid]);

$byFormat = rows('
    SELECT
      CASE
        WHEN format IS NULL OR format="" OR format="unknown" THEN "未填写"
        WHEN format="paper" THEN "纸质书"
        WHEN format="ebook" THEN "电子书"
        WHEN format="audiobook" THEN "有声书"
        ELSE format
      END AS name,
      COUNT(*) AS c
    FROM books
    WHERE user_id=?
    GROUP BY name
    ORDER BY c DESC
', [$uid]);

$recent = rows('
    SELECT
        COALESCE(b.title, t.raw_book_title) AS title,
        COALESCE(t.actual_finish_date, b.final_finish_date, t.planned_finish_date) AS finish_date,
        COALESCE(b.author, t.raw_author) AS author,
        b.douban_rating,
        b.douban_read_count
    FROM reading_tasks t
    LEFT JOIN books b ON b.id=t.book_id
    WHERE t.user_id=?
    ORDER BY COALESCE(t.actual_finish_date, b.final_finish_date, t.planned_finish_date, t.id) DESC
    LIMIT 12
', [$uid]);

$title = '统计';
include __DIR__ . '/../app/layout/header.php';
?>

<div class="stats-page">

    <section class="stats-hero card">
        <div>
            <p class="stats-eyebrow">Reading Analytics</p>
            <p class="muted">这里汇总你的阅读历史、书库结构、豆瓣信息和阅读计划。导入历史不会计入阅读计划。</p>
        </div>
    </section>

    <section class="stats-metrics">
        <div class="stats-metric">
            <span>书库总数</span>
            <strong><?= e($totalBooks) ?></strong>
        </div>
        <div class="stats-metric">
            <span>阅读历史</span>
            <strong><?= e($totalHistory) ?></strong>
        </div>
        <div class="stats-metric">
            <span>已完成</span>
            <strong><?= e($completedHistory) ?></strong>
        </div>
        <div class="stats-metric">
            <span>已获取豆瓣</span>
            <strong><?= e($withDouban) ?></strong>
        </div>
        <div class="stats-metric">
            <span>自我均分</span>
            <strong><?= e($avgSelf ?: '-') ?></strong>
        </div>
        <div class="stats-metric">
            <span>豆瓣均分</span>
            <strong><?= e($avgDouban ?: '-') ?></strong>
        </div>
        <div class="stats-metric">
            <span>阅读计划</span>
            <strong><?= e($plans) ?></strong>
        </div>
        <div class="stats-metric">
            <span>计划目标</span>
            <strong><?= e($planTarget) ?></strong>
        </div>
    </section>

    <section class="stats-panels">
        <div class="stats-panel card">
            <h2>作者国籍分布</h2>
            <div class="stats-list">
                <?php foreach ($byCountry as $r): ?>
                    <?php $pct = $totalBooks ? min(100, round($r['c'] / $totalBooks * 100)) : 0; ?>
                    <div class="stats-row">
                        <div class="stats-row-head">
                            <span title="<?= e($r['name']) ?>"><?= e($r['name']) ?></span>
                            <b><?= e($r['c']) ?></b>
                        </div>
                        <div class="stats-bar"><i style="width: <?= e($pct) ?>%"></i></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="stats-panel card">
            <h2>出版社 Top 12</h2>
            <div class="stats-list">
                <?php foreach ($byPublisher as $r): ?>
                    <?php $pct = $totalBooks ? min(100, round($r['c'] / $totalBooks * 100)) : 0; ?>
                    <div class="stats-row">
                        <div class="stats-row-head">
                            <span title="<?= e($r['name']) ?>"><?= e($r['name']) ?></span>
                            <b><?= e($r['c']) ?></b>
                        </div>
                        <div class="stats-bar"><i style="width: <?= e($pct) ?>%"></i></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="stats-panel card">
            <h2>阅读形式</h2>
            <div class="stats-list">
                <?php foreach ($byFormat as $r): ?>
                    <?php $pct = $totalBooks ? min(100, round($r['c'] / $totalBooks * 100)) : 0; ?>
                    <div class="stats-row">
                        <div class="stats-row-head">
                            <span><?= e($r['name']) ?></span>
                            <b><?= e($r['c']) ?></b>
                        </div>
                        <div class="stats-bar"><i style="width: <?= e($pct) ?>%"></i></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="stats-panel card">
            <h2>最近阅读历史</h2>
            <div class="stats-table-wrap">
                <table class="stats-table">
                    <tr>
                        <th>书名</th>
                        <th>作者</th>
                        <th>日期</th>
                        <th>豆瓣评分</th>
                        <th>豆瓣阅读人数</th>
                    </tr>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td title="<?= e($r['title'] ?? '') ?>"><?= e($r['title'] ?? '') ?></td>
                            <td title="<?= e($r['author'] ?? '') ?>"><?= e($r['author'] ?? '') ?></td>
                            <td><?= e($r['finish_date'] ?? '') ?></td>
                            <td><?= e($r['douban_rating'] ?? '') ?></td>
                            <td><?= e($r['douban_read_count'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </section>

</div>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
