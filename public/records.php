<?php
require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$uid = (int)$user['id'];

$savedView = (string)($_COOKIE['yx_records_view'] ?? '');
$initialView = in_array($savedView, ['cards', 'table'], true) ? $savedView : 'cards';

$where = ['t.user_id = ?'];
$args = [$uid];

$keyword = trim($_GET['keyword'] ?? '');
if ($keyword !== '') {
    $where[] = '(
        b.title LIKE ? OR t.raw_book_title LIKE ? OR
        b.author LIKE ? OR t.raw_author LIKE ? OR
        b.publisher LIKE ? OR t.task_publisher LIKE ? OR
        b.author_country LIKE ? OR t.task_author_country LIKE ?
    )';
    for ($i = 0; $i < 8; $i++) {
        $args[] = "%{$keyword}%";
    }
}

$status = trim($_GET['status'] ?? '');
$validStatuses = ['unread', 'reading', 'completed', 'unfinished', 'abandoned'];
if (in_array($status, $validStatuses, true)) {
    $where[] = 't.status = ?';
    $args[] = $status;
} else {
    $status = '';
}

$year = trim($_GET['year'] ?? '');
if ($year !== '' && preg_match('/^20\d{2}$/', $year)) {
    $where[] = 'YEAR(COALESCE(t.actual_finish_date, b.final_finish_date, t.planned_finish_date, DATE(t.created_at))) = ?';
    $args[] = (int)$year;
} else {
    $year = '';
}

$sortKey = trim($_GET['sort'] ?? 'index_asc');
$sortOptions = [
    'index_asc' => 'CAST(COALESCE(NULLIF(b.original_index,""), NULLIF(t.task_original_index,""), t.id) AS UNSIGNED) ASC, t.id ASC',
    'finish_desc' => 'COALESCE(t.actual_finish_date, b.final_finish_date, t.planned_finish_date, DATE(t.created_at)) DESC, t.id DESC',
    'finish_asc' => 'COALESCE(t.actual_finish_date, b.final_finish_date, t.planned_finish_date, DATE(t.created_at)) ASC, t.id ASC',
    'title_asc' => 'COALESCE(NULLIF(b.title,""), NULLIF(t.raw_book_title,"")) ASC, t.id ASC',
    'score_desc' => 'COALESCE(t.rating_score, b.rating_score) DESC, t.id DESC',
];
$orderBy = $sortOptions[$sortKey] ?? $sortOptions['index_asc'];
if (!isset($sortOptions[$sortKey])) {
    $sortKey = 'index_asc';
}

$sql = '
SELECT
    t.id AS task_id,
    t.raw_book_title,
    t.raw_author,
    t.task_original_index,
    t.task_format,
    t.task_platform,
    t.task_author_country,
    t.task_publisher,
    t.planned_start_date,
    t.planned_finish_date,
    t.actual_start_date,
    t.actual_finish_date,
    t.status AS history_status,
    t.progress AS history_progress,
    t.rating_score AS history_rating_score,
    t.comment,

    b.id AS book_id,
    b.original_index,
    b.title,
    b.author,
    b.author_country,
    b.publisher,
    b.tags,
    b.format,
    b.platform,
    b.first_start_date,
    b.final_finish_date,
    b.rating_score,
    b.self_rating_note,
    b.douban_rating,
    b.douban_read_count,
    b.douban_url,
    p.name AS plan_name
FROM reading_tasks t
LEFT JOIN books b ON b.id = t.book_id
LEFT JOIN reading_plans p ON p.id = t.plan_id AND p.user_id = t.user_id
WHERE ' . implode(' AND ', $where) . '
ORDER BY ' . $orderBy . '
LIMIT 1000
';

$stmt = db()->prepare($sql);
$stmt->execute($args);
$rawRows = $stmt->fetchAll();

$statusNames = [
    'unread' => '未读',
    'reading' => '在读',
    'completed' => '已完成',
    'unfinished' => '未完成',
    'abandoned' => '弃读',
];

$formatNames = [
    'paper' => '纸质书',
    'ebook' => '电子书',
    'audiobook' => '有声书',
    'unknown' => '',
];

$makeReadingPeriod = static function (string $startDate, string $finishDate): array {
    $startDate = trim($startDate);
    $finishDate = trim($finishDate);

    if ($startDate === '' && $finishDate === '') {
        return ['', ''];
    }

    if ($startDate !== '' && $finishDate !== '') {
        $period = $startDate === $finishDate
            ? $startDate
            : $startDate . ' 至 ' . $finishDate;
        $days = '';
        try {
            $start = new DateTimeImmutable($startDate);
            $finish = new DateTimeImmutable($finishDate);
            if ($finish >= $start) {
                $days = (string)($start->diff($finish)->days + 1);
            }
        } catch (Throwable $e) {
            $days = '';
        }
        return [$period, $days];
    }

    return [$startDate !== '' ? '开始于 ' . $startDate : '完成于 ' . $finishDate, ''];
};

$rows = [];
foreach ($rawRows as $row) {
    $bookTitle = trim((string)($row['title'] ?: ($row['raw_book_title'] ?? '')));
    $author = trim((string)($row['author'] ?: ($row['raw_author'] ?? '')));
    $publisher = trim((string)($row['publisher'] ?: ($row['task_publisher'] ?? '')));
    $country = trim((string)($row['author_country'] ?: ($row['task_author_country'] ?? '')));
    $formatCode = (string)($row['format'] ?: ($row['task_format'] ?? ''));
    $platform = trim((string)($row['platform'] ?: ($row['task_platform'] ?? '')));
    $startDate = (string)($row['actual_start_date'] ?: ($row['first_start_date'] ?: ($row['planned_start_date'] ?? '')));
    $finishDate = (string)($row['actual_finish_date'] ?: ($row['final_finish_date'] ?: ($row['planned_finish_date'] ?? '')));
    $score = $row['rating_score'] ?: ($row['self_rating_note'] ?: ($row['history_rating_score'] ?? ''));
    $statusCode = (string)($row['history_status'] ?? 'completed');
    $seq = $row['original_index'] ?: ($row['task_original_index'] ?: $row['task_id']);
    [$readingPeriod, $readingDays] = $makeReadingPeriod($startDate, $finishDate);

    $rows[] = [
        'task_id' => (int)$row['task_id'],
        'book_id' => !empty($row['book_id']) ? (int)$row['book_id'] : null,
        'seq' => (string)$seq,
        'title' => $bookTitle !== '' ? $bookTitle : '未命名书籍',
        'author' => $author,
        'publisher' => $publisher,
        'country' => $country,
        'format' => $formatNames[$formatCode] ?? $formatCode,
        'platform' => $platform,
        'start_date' => $startDate,
        'finish_date' => $finishDate,
        'reading_period' => $readingPeriod,
        'reading_days' => $readingDays,
        'score' => (string)$score,
        'status_code' => $statusCode,
        'status' => $statusNames[$statusCode] ?? '已完成',
        'progress' => (string)($row['history_progress'] ?? '100'),
        'comment' => trim((string)($row['comment'] ?? '')),
        'tags' => trim((string)($row['tags'] ?? '')),
        'douban_rating' => (string)($row['douban_rating'] ?? ''),
        'douban_read_count' => (string)($row['douban_read_count'] ?? ''),
        'douban_url' => trim((string)($row['douban_url'] ?? '')),
        'plan_name' => trim((string)($row['plan_name'] ?? '')),
    ];
}

$total = count($rows);
$currentYear = date('Y');
$queryBase = $_GET;
unset($queryBase['year']);
$allUrl = 'records.php' . ($queryBase ? '?' . http_build_query($queryBase) : '');
$yearQuery = array_merge($queryBase, ['year' => $currentYear]);
$thisYearUrl = 'records.php?' . http_build_query($yearQuery);

$exportRows = array_map(static function (array $row): array {
    return [
        'seq' => $row['seq'],
        'title' => $row['title'],
        'author' => $row['author'],
        'country' => $row['country'],
        'publisher' => $row['publisher'],
        'start_date' => $row['start_date'],
        'finish_date' => $row['finish_date'],
        'reading_period' => $row['reading_period'],
        'reading_days' => $row['reading_days'],
        'score' => $row['score'],
        'status_code' => $row['status_code'],
        'status' => $row['status'],
        'plan_name' => $row['plan_name'],
        'format' => $row['format'],
        'platform' => $row['platform'],
        'comment' => $row['comment'],
    ];
}, $rows);

$title = '已读历史';
include __DIR__ . '/../app/layout/header.php';
?>

<section class="yx-records-shell" id="yxRecordsPage" data-view="<?= e($initialView) ?>">
    <header class="yx-records-head">
        <div>
            <p class="yx-eyebrow">我的阅读档案</p>
            <p class="yx-records-count">当前显示 <strong><?= e($total) ?></strong> 本<?= $total >= 1000 ? '，最多加载 1000 本' : '' ?></p>
        </div>
        <div class="yx-records-head-actions">
            <a class="yx-round-add" href="record_create.php">新增</a>
            <button class="yx-function-button" type="button" id="yxOpenRecordsTools" aria-expanded="false">功能</button>
        </div>
    </header>

    <form class="yx-records-search" method="get" action="records.php" role="search">
        <label class="sr-only" for="yxRecordsKeyword">搜索已读书目</label>
        <span aria-hidden="true">⌕</span>
        <input id="yxRecordsKeyword" type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="搜索书名、作者或出版社" autocomplete="off" enterkeyhint="search">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <?php if ($year !== ''): ?><input type="hidden" name="year" value="<?= e($year) ?>"><?php endif; ?>
        <?php if ($sortKey !== 'index_asc'): ?><input type="hidden" name="sort" value="<?= e($sortKey) ?>"><?php endif; ?>
        <?php if ($keyword !== ''): ?><a href="records.php" aria-label="清除搜索">×</a><?php endif; ?>
        <button type="submit">搜索</button>
    </form>

    <div class="yx-records-chips" aria-label="快捷操作">
        <a class="<?= $year === '' && $status === '' ? 'active' : '' ?>" href="<?= e($allUrl) ?>">全部</a>
        <a class="<?= $year === $currentYear ? 'active' : '' ?>" href="<?= e($thisYearUrl) ?>">今年</a>
        <button type="button" id="yxOpenRecordsFilters">筛选</button>
        <div class="yx-view-switch" id="yxRecordsViewSwitch" role="group" aria-label="显示方式">
            <button type="button" data-yx-view="cards" class="<?= $initialView === 'cards' ? 'is-active' : '' ?>" aria-pressed="<?= $initialView === 'cards' ? 'true' : 'false' ?>">卡片</button>
            <button type="button" data-yx-view="table" class="<?= $initialView === 'table' ? 'is-active' : '' ?>" aria-pressed="<?= $initialView === 'table' ? 'true' : 'false' ?>">表格</button>
        </div>
    </div>

    <?php if ($keyword !== '' || $status !== '' || $year !== ''): ?>
        <div class="yx-active-filter">
            <span>已应用筛选</span>
            <?php if ($keyword !== ''): ?><b>“<?= e($keyword) ?>”</b><?php endif; ?>
            <?php if ($status !== ''): ?><b><?= e($statusNames[$status] ?? $status) ?></b><?php endif; ?>
            <?php if ($year !== ''): ?><b><?= e($year) ?> 年</b><?php endif; ?>
            <a href="records.php">清除</a>
        </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="yx-empty-state yx-records-empty">
            <div aria-hidden="true">◇</div>
            <h2>没有找到阅读记录</h2>
            <p><?= $keyword !== '' || $status !== '' || $year !== '' ? '换一个关键词或清除筛选后再试。' : '录入第一本读过的书，开始建立你的阅读档案。' ?></p>
            <a class="btn" href="<?= $keyword !== '' || $status !== '' || $year !== '' ? 'records.php' : 'record_create.php' ?>"><?= $keyword !== '' || $status !== '' || $year !== '' ? '清除筛选' : '录入第一本' ?></a>
        </div>
    <?php else: ?>
        <div class="yx-record-card-list" id="yxRecordCardList">
            <?php foreach ($rows as $index => $row): ?>
                <?php
                    $tags = array_values(array_filter(array_map('trim', preg_split('/[,，;；|]/u', $row['tags']) ?: [])));
                ?>
                <article class="yx-record-card" data-record-index="<?= e($index) ?>" data-record-title="<?= e($row['title']) ?>">
                    <div class="yx-record-card-head">
                        <span class="yx-record-number" aria-label="编号 <?= e($row['seq']) ?>">#<?= e($row['seq']) ?></span>
                        <h2 class="yx-record-card-title"><?= e($row['title']) ?></h2>
                        <button class="yx-record-detail-button" type="button" data-yx-record-view="<?= e($row['task_id']) ?>">详情</button>
                    </div>

                    <div class="yx-record-card-summary">
                        <p class="yx-record-byline">
                            <span><?= e($row['author'] ?: '作者未录入') ?></span>
                            <?php if ($row['country'] !== ''): ?><span><?= e($row['country']) ?></span><?php endif; ?>
                            <?php if ($row['publisher'] !== ''): ?><span><?= e($row['publisher']) ?></span><?php endif; ?>
                        </p>

                        <div class="yx-record-card-compact-meta">
                            <?php if ($row['reading_period'] !== ''): ?>
                                <span class="yx-reading-period"><b>阅读</b><?= e($row['reading_period']) ?><?= $row['reading_days'] !== '' ? ' · ' . e($row['reading_days']) . ' 天' : '' ?></span>
                            <?php elseif ($row['finish_date'] !== ''): ?>
                                <span><b>完成</b><?= e($row['finish_date']) ?></span>
                            <?php endif; ?>
                            <?php if ($row['score'] !== ''): ?><span class="yx-score">★ <?= e($row['score']) ?></span><?php endif; ?>
                            <span><?= e($row['status']) ?></span>
                            <?php if ($row['format'] !== ''): ?><span><?= e($row['format']) ?></span><?php endif; ?>
                        </div>

                        <?php if ($tags): ?>
                            <div class="yx-record-tags yx-record-tags-compact">
                                <?php foreach (array_slice($tags, 0, 2) as $tag): ?><span><?= e($tag) ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="yx-record-table-view" id="yxRecordTableView">
            <div class="yx-native-table-scroll">
                <table class="yx-clean-record-table">
                    <thead>
                        <tr>
                            <th>序号</th><th>书名</th><th>作者</th><th>国籍</th><th>出版社</th>
                            <th>形式</th><th>平台</th><th>开始日期</th><th>完成日期</th><th>评分</th>
                            <th>豆瓣评分</th><th>状态</th><th>备注</th><th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e($row['seq']) ?></td>
                                <td><span class="yx-table-book-title"><?= e($row['title']) ?></span></td>
                                <td><?= e($row['author']) ?></td>
                                <td><?= e($row['country']) ?></td>
                                <td><?= e($row['publisher']) ?></td>
                                <td><?= e($row['format']) ?></td>
                                <td><?= e($row['platform']) ?></td>
                                <td><?= e($row['start_date']) ?></td>
                                <td><?= e($row['finish_date']) ?></td>
                                <td><?= e($row['score']) ?></td>
                                <td><?= e($row['douban_rating']) ?></td>
                                <td><?= e($row['status']) ?></td>
                                <td title="<?= e($row['comment']) ?>"><?= e($row['comment']) ?></td>
                                <td><button class="yx-table-detail-button" type="button" data-yx-record-view="<?= e($row['task_id']) ?>">详情</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="yx-table-hint">表格可直接左右滑动，并支持双指缩放；不再提供横向拖动滑块。</p>
        </div>
    <?php endif; ?>
</section>

<div class="yx-vertical-scrubber" id="yxVerticalScrubber" hidden aria-label="上下快速定位">
    <div class="yx-vertical-track">
        <button class="yx-vertical-thumb" type="button" aria-label="按住上下拖动">
            <span class="yx-vertical-bubble">1 / <?= e(max(1, $total)) ?></span>
        </button>
    </div>
</div>

<div class="yx-sheet-backdrop" id="yxRecordsFilterSheet" hidden>
    <section class="yx-bottom-sheet" role="dialog" aria-modal="true" aria-labelledby="yxRecordsFilterTitle">
        <div class="yx-sheet-head">
            <div>
                <p>已读历史</p>
                <h2 id="yxRecordsFilterTitle">筛选与排序</h2>
            </div>
            <button class="yx-sheet-close" type="button" data-yx-filter-close>关闭</button>
        </div>

        <form class="yx-sheet-section yx-record-filter-form" method="get" action="records.php">
            <?php if ($keyword !== ''): ?><input type="hidden" name="keyword" value="<?= e($keyword) ?>"><?php endif; ?>
            <label>阅读状态
                <select name="status">
                    <option value="">全部状态</option>
                    <?php foreach ($statusNames as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>完成年份
                <input type="number" name="year" value="<?= e($year) ?>" min="1900" max="<?= e(date('Y') + 1) ?>" placeholder="全部年份" inputmode="numeric">
            </label>
            <label class="yx-filter-wide">排序方式
                <select name="sort">
                    <option value="index_asc" <?= $sortKey === 'index_asc' ? 'selected' : '' ?>>录入序号</option>
                    <option value="finish_desc" <?= $sortKey === 'finish_desc' ? 'selected' : '' ?>>完成时间：新到旧</option>
                    <option value="finish_asc" <?= $sortKey === 'finish_asc' ? 'selected' : '' ?>>完成时间：旧到新</option>
                    <option value="title_asc" <?= $sortKey === 'title_asc' ? 'selected' : '' ?>>书名</option>
                    <option value="score_desc" <?= $sortKey === 'score_desc' ? 'selected' : '' ?>>个人评分</option>
                </select>
            </label>
            <div class="yx-sheet-form-actions">
                <a href="records.php">重置</a>
                <button type="submit">查看结果</button>
            </div>
        </form>
    </section>
</div>

<div class="yx-sheet-backdrop" id="yxRecordsToolsSheet" hidden>
    <section class="yx-bottom-sheet" role="dialog" aria-modal="true" aria-labelledby="yxRecordsToolsTitle">
        <div class="yx-sheet-head">
            <div>
                <p>已读历史</p>
                <h2 id="yxRecordsToolsTitle">功能</h2>
            </div>
            <button class="yx-sheet-close" type="button" data-yx-tools-close>关闭</button>
        </div>
        <div class="yx-tools-list">
            <button type="button" id="yxOpenExportOptions"><span>导出记录</span><small>按范围、日期、评分或计划选择</small></button>
            <button type="button" id="openDoubanSyncModal"><span>获取豆瓣信息</span><small>批量补充书目信息</small></button>
            <a href="douban_import.php"><span>导入豆瓣 CSV</span><small>从文件更新豆瓣信息</small></a>
            <a href="dedupe_books.php"><span>合并去重</span><small>检查并合并重复书目</small></a>
        </div>
    </section>
</div>

<div class="yx-sheet-backdrop" id="yxRecordsExportSheet" hidden>
    <section class="yx-bottom-sheet yx-export-sheet" role="dialog" aria-modal="true" aria-labelledby="yxRecordsExportTitle">
        <div class="yx-sheet-head">
            <div>
                <p>当前筛选结果</p>
                <h2 id="yxRecordsExportTitle">选择导出范围</h2>
            </div>
            <button class="yx-sheet-close" type="button" data-yx-export-close>关闭</button>
        </div>
        <div class="yx-export-options" id="yxExportOptions">
            <p class="yx-export-help">下面条件可以组合。“第几条”按当前排序结果计算。</p>
            <div class="yx-export-grid">
                <label>从第几条
                    <input type="number" id="yxExportPositionStart" min="1" max="<?= e(max(1, $total)) ?>" placeholder="1" inputmode="numeric">
                </label>
                <label>到第几条
                    <input type="number" id="yxExportPositionEnd" min="1" max="<?= e(max(1, $total)) ?>" placeholder="<?= e(max(1, $total)) ?>" inputmode="numeric">
                </label>
                <label>完成日期从
                    <input type="date" id="yxExportDateStart">
                </label>
                <label>完成日期到
                    <input type="date" id="yxExportDateEnd">
                </label>
                <label>阅读年份
                    <select id="yxExportYear"><option value="">全部年份</option></select>
                </label>
                <label>阅读状态
                    <select id="yxExportStatus">
                        <option value="">全部状态</option>
                        <?php foreach ($statusNames as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>最低评分
                    <input type="number" id="yxExportScoreMin" min="0" max="10" step="0.1" placeholder="不限" inputmode="decimal">
                </label>
                <label>最高评分
                    <input type="number" id="yxExportScoreMax" min="0" max="10" step="0.1" placeholder="不限" inputmode="decimal">
                </label>
                <label class="yx-export-wide">所属计划
                    <select id="yxExportPlan"><option value="">全部计划</option></select>
                </label>
                <label class="yx-export-wide">图片导出方式
                    <select id="yxImageExportMode">
                        <option value="pages">分页 PNG（保留原模式）</option>
                        <option value="pdf">完整合并 PDF</option>
                        <option value="stitch">完整拼图长图</option>
                        <option value="recent">最近书目单图（最多 84 本）</option>
                    </select>
                </label>
            </div>
            <div class="yx-export-summary">预计导出 <strong id="yxExportPreviewCount"><?= e($total) ?></strong> 条</div>
            <div class="yx-export-actions">
                <button type="button" class="yx-secondary-button" id="yxResetExportOptions">重置条件</button>
                <button type="button" id="yxExportRecordsText">TXT</button>
                <button type="button" id="yxExportRecordsCsv">CSV</button>
                <button type="button" id="yxExportRecordsImage">生成</button>
            </div>
        </div>
    </section>
</div>

<div class="yx-image-export" id="yxImageExport" hidden>
    <section role="dialog" aria-modal="true" aria-labelledby="yxImageExportTitle">
        <header>
            <div><p>当前筛选结果</p><h2 id="yxImageExportTitle">导出截图</h2></div>
            <button type="button" data-yx-image-close aria-label="关闭">×</button>
        </header>
        <p class="yx-image-export-note">选择的导出内容将按三列紧凑排版生成。</p>
        <div class="yx-image-export-toolbar">
            <button type="button" id="yxDownloadAllImages" hidden>下载全部分页图片</button>
            <button type="button" id="yxDownloadPdf" hidden>下载合并 PDF</button>
            <button type="button" id="yxDownloadStitched" hidden>下载拼图长图</button>
        </div>
        <div id="yxImageExportList"></div>
    </section>
</div>


<div class="yx-record-view-modal" id="yxRecordViewModal" hidden>
    <section class="yx-record-view-dialog" role="dialog" aria-modal="true" aria-labelledby="yxRecordViewTitle">
        <header class="yx-record-view-head">
            <div>
                <p id="yxRecordViewNumber">阅读记录</p>
                <h2 id="yxRecordViewTitle">书目信息</h2>
                <span id="yxRecordViewAuthor"></span>
            </div>
            <button type="button" data-yx-record-view-close aria-label="关闭">×</button>
        </header>
        <div class="yx-record-view-loading" id="yxRecordViewLoading">正在加载全部信息…</div>
        <div class="yx-record-view-content" id="yxRecordViewContent"></div>
        <footer class="yx-record-view-actions">
            <button type="button" data-yx-record-view-close>关闭</button>
            <a id="yxRecordViewEdit" href="#">编辑</a>
        </footer>
    </section>
</div>


<script id="yxRecordsData" type="application/json"><?= json_encode($exportRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
    const root = document.getElementById('yxRecordsPage');
    if (!root) return;

    const filterSheet = document.getElementById('yxRecordsFilterSheet');
    const toolsSheet = document.getElementById('yxRecordsToolsSheet');
    const exportSheet = document.getElementById('yxRecordsExportSheet');
    const openFilters = document.getElementById('yxOpenRecordsFilters');
    const openTools = document.getElementById('yxOpenRecordsTools');
    const openExport = document.getElementById('yxOpenExportOptions');
    const viewButtons = Array.from(document.querySelectorAll('[data-yx-view]'));
    const cards = Array.from(document.querySelectorAll('.yx-record-card'));
    const tableRows = Array.from(document.querySelectorAll('.yx-clean-record-table tbody tr'));
    const scrubber = document.getElementById('yxVerticalScrubber');
    const dataNode = document.getElementById('yxRecordsData');
    const recordViewModal = document.getElementById('yxRecordViewModal');
    const recordViewTitle = document.getElementById('yxRecordViewTitle');
    const recordViewNumber = document.getElementById('yxRecordViewNumber');
    const recordViewAuthor = document.getElementById('yxRecordViewAuthor');
    const recordViewLoading = document.getElementById('yxRecordViewLoading');
    const recordViewContent = document.getElementById('yxRecordViewContent');
    const recordViewEdit = document.getElementById('yxRecordViewEdit');
    const imageModal = document.getElementById('yxImageExport');
    const imageList = document.getElementById('yxImageExportList');
    const imageNote = imageModal ? imageModal.querySelector('.yx-image-export-note') : null;
    const imageTitle = document.getElementById('yxImageExportTitle');
    const imageMode = document.getElementById('yxImageExportMode');
    const imageDownloadAll = document.getElementById('yxDownloadAllImages');
    const imageDownloadPdf = document.getElementById('yxDownloadPdf');
    const imageDownloadStitched = document.getElementById('yxDownloadStitched');
    let records = [];
    let updateScrubber = function () {};
    let recordViewController = null;
    let imageRows = [];
    let imagePages = [];
    let imageObjectUrls = [];
    let imagePdfUrl = '';
    let imagePdfFilename = '';
    let imageStitchedUrl = '';
    let imageStitchedFilename = '';
    let imageGeneration = 0;

    try { records = JSON.parse(dataNode ? dataNode.textContent : '[]'); } catch (ignore) {}

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function openSheet(sheet) {
        [filterSheet, toolsSheet, exportSheet].forEach(function (item) {
            if (item) item.hidden = item !== sheet;
        });
        if (!sheet) {
            document.body.classList.remove('yx-sheet-open');
            if (openTools) openTools.setAttribute('aria-expanded', 'false');
            if (scrubber && (!recordViewModal || recordViewModal.hidden)) scrubber.classList.remove('is-suppressed');
            return;
        }
        document.body.classList.add('yx-sheet-open');
        if (openTools) openTools.setAttribute('aria-expanded', sheet === toolsSheet ? 'true' : 'false');
        if (scrubber) scrubber.classList.add('is-suppressed');
    }

    if (openFilters) openFilters.addEventListener('click', function () { openSheet(filterSheet); });
    if (openTools) openTools.addEventListener('click', function () { openSheet(toolsSheet); });
    if (openExport) openExport.addEventListener('click', function () { openSheet(exportSheet); updateExportPreview(); });
    const doubanSyncButton = document.getElementById('openDoubanSyncModal');
    if (doubanSyncButton) doubanSyncButton.addEventListener('click', function () { openSheet(null); });

    [filterSheet, toolsSheet, exportSheet].forEach(function (sheet) {
        if (!sheet) return;
        sheet.addEventListener('click', function (event) {
            if (event.target === sheet || event.target.closest('[data-yx-filter-close], [data-yx-tools-close], [data-yx-export-close]')) {
                openSheet(null);
            }
        });
    });

    function setView(view) {
        if (view !== 'cards' && view !== 'table') return;
        root.dataset.view = view;
        document.cookie = 'yx_records_view=' + view + '; path=/; max-age=31536000; samesite=lax';
        try { localStorage.setItem('yx-records-view', view); } catch (ignore) {}
        viewButtons.forEach(function (button) {
            const active = button.dataset.yxView === view;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        updateScrubberVisibility();
    }

    viewButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setView(button.dataset.yxView || 'cards');
        });
    });
    setView(root.dataset.view === 'table' ? 'table' : 'cards');

    function closeRecordView() {
        if (!recordViewModal || recordViewModal.hidden) return;
        if (recordViewController) recordViewController.abort();
        recordViewController = null;
        recordViewModal.hidden = true;
        document.body.classList.remove('yx-record-view-open');
        if (scrubber) scrubber.classList.remove('is-suppressed');
    }

    function renderRecordGroups(groups) {
        if (!recordViewContent) return;
        const cleanGroups = (Array.isArray(groups) ? groups : []).map(function (group) {
            const items = (Array.isArray(group.items) ? group.items : []).filter(function (item) {
                return String(item && item.value != null ? item.value : '').trim() !== '';
            });
            return {name: group.name || '其他信息', items: items};
        }).filter(function (group) { return group.items.length > 0; });

        if (!cleanGroups.length) {
            recordViewContent.innerHTML = '<div class="yx-record-view-empty">这条记录暂无可显示内容。</div>';
            return;
        }

        recordViewContent.innerHTML = cleanGroups.map(function (group) {
            const body = group.items.map(function (item) {
                const raw = String(item.value == null ? '' : item.value).trim();
                const isUrl = /^https?:\/\//i.test(raw);
                const value = isUrl
                    ? '<a href="' + escapeHtml(raw) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(raw) + '</a>'
                    : '<span>' + escapeHtml(raw).replace(/\n/g, '<br>') + '</span>';
                return '<div class="yx-record-view-field"><dt>' + escapeHtml(item.label) + '</dt><dd>' + value + '</dd></div>';
            }).join('');
            return '<section class="yx-record-view-group"><h3>' + escapeHtml(group.name) + '</h3><dl>' + body + '</dl></section>';
        }).join('');
    }

    function openRecordView(taskId) {
        if (!recordViewModal || !taskId) return;
        openSheet(null);
        if (scrubber) scrubber.classList.add('is-suppressed');
        recordViewModal.hidden = false;
        document.body.classList.add('yx-record-view-open');
        recordViewTitle.textContent = '书目信息';
        recordViewNumber.textContent = '阅读记录';
        recordViewAuthor.textContent = '';
        recordViewContent.innerHTML = '';
        recordViewLoading.hidden = false;
        recordViewLoading.textContent = '正在加载全部信息…';
        recordViewEdit.hidden = true;

        if (recordViewController) recordViewController.abort();
        recordViewController = new AbortController();

        fetch('record_view_api.php?id=' + encodeURIComponent(taskId), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
            signal: recordViewController.signal
        })
            .then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !payload.ok) throw new Error(payload.message || '加载失败');
                    return payload.record;
                });
            })
            .then(function (record) {
                recordViewLoading.hidden = true;
                recordViewTitle.textContent = record.title || '未命名书籍';
                recordViewNumber.textContent = record.number ? ('#' + record.number) : '阅读记录';
                recordViewAuthor.textContent = record.author || '';
                recordViewEdit.href = record.edit_url || ('record_edit.php?id=' + taskId);
                recordViewEdit.hidden = false;
                renderRecordGroups(record.groups);
            })
            .catch(function (error) {
                if (error.name === 'AbortError') return;
                recordViewLoading.hidden = false;
                recordViewLoading.textContent = error.message || '加载失败，请稍后重试。';
            });
    }

    document.addEventListener('click', function (event) {
        const opener = event.target.closest('[data-yx-record-view]');
        if (opener) {
            event.preventDefault();
            openRecordView(opener.getAttribute('data-yx-record-view'));
            return;
        }
        if (recordViewModal && (event.target === recordViewModal || event.target.closest('[data-yx-record-view-close]'))) {
            closeRecordView();
        }
    });

    const exportInputs = Array.from(document.querySelectorAll('#yxExportOptions input, #yxExportOptions select:not(#yxImageExportMode)'));
    const positionStart = document.getElementById('yxExportPositionStart');
    const positionEnd = document.getElementById('yxExportPositionEnd');
    const dateStart = document.getElementById('yxExportDateStart');
    const dateEnd = document.getElementById('yxExportDateEnd');
    const exportYear = document.getElementById('yxExportYear');
    const exportStatus = document.getElementById('yxExportStatus');
    const scoreMin = document.getElementById('yxExportScoreMin');
    const scoreMax = document.getElementById('yxExportScoreMax');
    const exportPlan = document.getElementById('yxExportPlan');
    const previewCount = document.getElementById('yxExportPreviewCount');

    function fillExportSelects() {
        if (exportYear) {
            const years = Array.from(new Set(records.map(function (row) { return (row.finish_date || '').slice(0, 4); }).filter(Boolean))).sort().reverse();
            years.forEach(function (year) {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year + ' 年';
                exportYear.appendChild(option);
            });
        }
        if (exportPlan) {
            const plans = Array.from(new Set(records.map(function (row) { return row.plan_name || ''; }).filter(Boolean))).sort();
            plans.forEach(function (plan) {
                const option = document.createElement('option');
                option.value = plan;
                option.textContent = plan;
                exportPlan.appendChild(option);
            });
        }
    }

    function numberValue(input) {
        if (!input || input.value.trim() === '') return null;
        const value = Number(input.value);
        return Number.isFinite(value) ? value : null;
    }

    function selectedRecords() {
        const from = numberValue(positionStart);
        const to = numberValue(positionEnd);
        const minScore = numberValue(scoreMin);
        const maxScore = numberValue(scoreMax);
        const start = dateStart ? dateStart.value : '';
        const end = dateEnd ? dateEnd.value : '';
        const year = exportYear ? exportYear.value : '';
        const status = exportStatus ? exportStatus.value : '';
        const plan = exportPlan ? exportPlan.value : '';

        return records.filter(function (row, index) {
            const position = index + 1;
            if (from !== null && position < from) return false;
            if (to !== null && position > to) return false;
            const finish = row.finish_date || '';
            if (start && (!finish || finish < start)) return false;
            if (end && (!finish || finish > end)) return false;
            if (year && finish.slice(0, 4) !== year) return false;
            if (status && row.status_code !== status) return false;
            if (plan && row.plan_name !== plan) return false;
            const score = Number.parseFloat(row.score);
            if (minScore !== null && (!Number.isFinite(score) || score < minScore)) return false;
            if (maxScore !== null && (!Number.isFinite(score) || score > maxScore)) return false;
            return true;
        });
    }

    function updateExportPreview() {
        const count = selectedRecords().length;
        if (previewCount) previewCount.textContent = String(count);
    }

    exportInputs.forEach(function (input) {
        input.addEventListener('input', updateExportPreview);
        input.addEventListener('change', updateExportPreview);
    });

    const resetExport = document.getElementById('yxResetExportOptions');
    if (resetExport) resetExport.addEventListener('click', function () {
        exportInputs.forEach(function (input) { input.value = ''; });
        updateExportPreview();
    });

    function requireExportRows() {
        const rows = selectedRecords();
        if (!rows.length) {
            alert('当前条件没有可导出的记录。');
            return null;
        }
        return rows;
    }

    function downloadBlob(content, type, filename) {
        const blob = new Blob([content], {type: type});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function exportText(rows) {
        const lines = ['阅心 · 已读历史', '导出时间：' + new Date().toLocaleString('zh-CN'), '记录数：' + rows.length, ''];
        rows.forEach(function (row) {
            lines.push('#' + row.seq + ' ' + row.title);
            const meta = [row.author, row.country, row.publisher, row.plan_name ? '计划：' + row.plan_name : '', row.reading_period ? '阅读 ' + row.reading_period + (row.reading_days ? ' · ' + row.reading_days + ' 天' : '') : '', row.score ? '评分 ' + row.score : '', row.status].filter(Boolean);
            if (meta.length) lines.push('   ' + meta.join(' · '));
            if (row.comment) lines.push('   备注：' + row.comment);
            lines.push('');
        });
        downloadBlob('\uFEFF' + lines.join('\n'), 'text/plain;charset=utf-8', '阅心-已读历史-' + new Date().toISOString().slice(0, 10) + '.txt');
    }

    function csvCell(value) {
        return '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"';
    }

    function exportCsv(rows) {
        const columns = [
            ['序号', 'seq'], ['书名', 'title'], ['作者', 'author'], ['国籍', 'country'], ['出版社', 'publisher'],
            ['形式', 'format'], ['平台', 'platform'], ['开始日期', 'start_date'], ['完成日期', 'finish_date'],
            ['阅读时间', 'reading_period'], ['阅读天数', 'reading_days'], ['评分', 'score'], ['状态', 'status'],
            ['所属计划', 'plan_name'], ['备注', 'comment']
        ];
        const lines = [columns.map(function (item) { return csvCell(item[0]); }).join(',')];
        rows.forEach(function (row) {
            lines.push(columns.map(function (item) { return csvCell(row[item[1]] || ''); }).join(','));
        });
        downloadBlob('\uFEFF' + lines.join('\r\n'), 'text/csv;charset=utf-8', '阅心-已读历史-' + new Date().toISOString().slice(0, 10) + '.csv');
    }

    const textButton = document.getElementById('yxExportRecordsText');
    const csvButton = document.getElementById('yxExportRecordsCsv');
    if (textButton) textButton.addEventListener('click', function () {
        const rows = requireExportRows();
        if (rows) exportText(rows);
    });
    if (csvButton) csvButton.addEventListener('click', function () {
        const rows = requireExportRows();
        if (rows) exportCsv(rows);
    });

    /* 图片导出 V14：分页 PNG、合并 PDF、拼图长图、最近书目单图 */
    const IMAGE_COLUMNS = 3;
    const IMAGE_ROWS_PER_PAGE = 28;
    const IMAGE_PAGE_SIZE = IMAGE_COLUMNS * IMAGE_ROWS_PER_PAGE;

    function roundedRect(ctx, x, y, width, height, radius) {
        const r = Math.min(radius, width / 2, height / 2);
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + width, y, x + width, y + height, r);
        ctx.arcTo(x + width, y + height, x, y + height, r);
        ctx.arcTo(x, y + height, x, y, r);
        ctx.arcTo(x, y, x + width, y, r);
        ctx.closePath();
    }

    function fitCanvasText(ctx, value, maxWidth) {
        let text = String(value == null ? '' : value).trim();
        if (!text) return '';
        if (ctx.measureText(text).width <= maxWidth) return text;
        while (text.length > 1 && ctx.measureText(text + '…').width > maxWidth) {
            text = text.slice(0, -1);
        }
        return text + '…';
    }

    /* YUEXIN_EXPORT_FONT_V15_PLUS_TWO */
    function canvasPageMetrics(rowCount) {
        const width = 1440;
        const outer = 48;
        const gapX = 14;
        const gapY = 12;
        const cardHeight = 184;
        const headerHeight = 150;
        const footerHeight = 64;
        const visibleRows = Math.max(1, Math.ceil(rowCount / IMAGE_COLUMNS));
        return {
            width: width,
            outer: outer,
            gapX: gapX,
            gapY: gapY,
            cardHeight: cardHeight,
            headerHeight: headerHeight,
            footerHeight: footerHeight,
            cardWidth: (width - outer * 2 - gapX * (IMAGE_COLUMNS - 1)) / IMAGE_COLUMNS,
            rowCount: visibleRows,
            height: headerHeight + visibleRows * cardHeight + Math.max(0, visibleRows - 1) * gapY + footerHeight
        };
    }

    function makeCanvasPage(rows, page, totalPages, allCount, options) {
        const opts = options || {};
        const metrics = canvasPageMetrics(rows.length);
        const canvas = document.createElement('canvas');
        canvas.width = metrics.width;
        canvas.height = metrics.height;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#f5f7f4';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#253028';
        ctx.font = '700 48px system-ui, -apple-system, Microsoft YaHei, sans-serif';
        ctx.fillText(opts.title || '阅心 · 已读历史', metrics.outer, 64);
        ctx.fillStyle = '#6f7971';
        ctx.font = '28px system-ui, -apple-system, Microsoft YaHei, sans-serif';
        const summary = opts.summary || ('共 ' + allCount + ' 本 · 第 ' + page + ' / ' + totalPages + ' 张 · 每行 3 本');
        ctx.fillText(summary, metrics.outer, 110);

        rows.forEach(function (row, index) {
            const column = index % IMAGE_COLUMNS;
            const rowIndex = Math.floor(index / IMAGE_COLUMNS);
            const x = metrics.outer + column * (metrics.cardWidth + metrics.gapX);
            const y = metrics.headerHeight + rowIndex * (metrics.cardHeight + metrics.gapY);
            const innerX = x + 16;
            const textWidth = metrics.cardWidth - 32;

            ctx.fillStyle = '#ffffff';
            roundedRect(ctx, x, y, metrics.cardWidth, metrics.cardHeight, 6);
            ctx.fill();
            ctx.strokeStyle = '#dce3dd';
            ctx.lineWidth = 1;
            ctx.stroke();

            ctx.fillStyle = '#253028';
            ctx.font = '700 32px system-ui, -apple-system, Microsoft YaHei, sans-serif';
            ctx.fillText(fitCanvasText(ctx, '#' + row.seq + '  ' + (row.title || '未命名书籍'), textWidth), innerX, y + 42);

            ctx.fillStyle = '#5f6b62';
            ctx.font = '26px system-ui, -apple-system, Microsoft YaHei, sans-serif';
            ctx.fillText(fitCanvasText(ctx, '作者：' + (row.author || '未录入'), textWidth), innerX, y + 86);

            const publication = [row.publisher || '出版社未录入', row.country || ''].filter(Boolean).join(' · ');
            ctx.fillText(fitCanvasText(ctx, publication, textWidth), innerX, y + 128);

            ctx.fillStyle = '#52715a';
            ctx.font = '700 24px system-ui, -apple-system, Microsoft YaHei, sans-serif';
            ctx.fillText('完成：' + (row.finish_date || '未录入'), innerX, y + 166);
        });

        ctx.fillStyle = '#818a83';
        ctx.font = '23px system-ui, -apple-system, Microsoft YaHei, sans-serif';
        ctx.fillText('由阅心生成 · ' + new Date().toLocaleDateString('zh-CN'), metrics.outer, canvas.height - 24);
        return canvas;
    }

    function canvasToBlob(canvas, type, quality) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) resolve(blob);
                else reject(new Error('图片生成失败'));
            }, type || 'image/png', quality);
        });
    }

    function splitImageRows(rows) {
        const chunks = [];
        for (let index = 0; index < rows.length; index += IMAGE_PAGE_SIZE) {
            chunks.push(rows.slice(index, index + IMAGE_PAGE_SIZE));
        }
        return chunks.length ? chunks : [[]];
    }

    function recentImageRows(rows) {
        return rows.slice().sort(function (a, b) {
            const dateA = String(a.finish_date || a.start_date || '');
            const dateB = String(b.finish_date || b.start_date || '');
            if (dateA !== dateB) return dateA < dateB ? 1 : -1;
            const seqA = Number.parseInt(a.seq, 10) || 0;
            const seqB = Number.parseInt(b.seq, 10) || 0;
            return seqB - seqA;
        }).slice(0, IMAGE_PAGE_SIZE);
    }

    function clearImagePages() {
        imageObjectUrls.forEach(function (url) { URL.revokeObjectURL(url); });
        imageObjectUrls = [];
        imagePages = [];
        imagePdfUrl = '';
        imagePdfFilename = '';
        imageStitchedUrl = '';
        imageStitchedFilename = '';
        if (imageList) imageList.innerHTML = '';
        if (imageDownloadAll) imageDownloadAll.hidden = true;
        if (imageDownloadPdf) imageDownloadPdf.hidden = true;
        if (imageDownloadStitched) imageDownloadStitched.hidden = true;
    }

    /* YUEXIN_DOWNLOAD_TOAST_V16 */
    let yxDownloadToastTimer = 0;

    function showDownloadToast(message) {
        let toast = document.getElementById('yxDownloadToast');

        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'yxDownloadToast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');

            Object.assign(toast.style, {
                position: 'fixed',
                top: 'calc(env(safe-area-inset-top, 0px) + 14px)',
                left: '50%',
                zIndex: '2147483647',
                maxWidth: 'calc(100vw - 32px)',
                padding: '10px 14px',
                borderRadius: '8px',
                background: '#426b4d',
                color: '#ffffff',
                boxShadow: '0 6px 18px rgba(24, 43, 30, .18)',
                fontSize: '15px',
                fontWeight: '700',
                lineHeight: '1.35',
                textAlign: 'center',
                whiteSpace: 'nowrap',
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                pointerEvents: 'none',
                opacity: '0',
                transform: 'translate(-50%, -8px)',
                transition: 'opacity .18s ease, transform .18s ease'
            });

            document.body.appendChild(toast);
        }

        window.clearTimeout(yxDownloadToastTimer);

        toast.textContent = message;
        toast.hidden = false;

        requestAnimationFrame(function () {
            toast.style.opacity = '1';
            toast.style.transform = 'translate(-50%, 0)';
        });

        yxDownloadToastTimer = window.setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translate(-50%, -8px)';

            window.setTimeout(function () {
                toast.hidden = true;
            }, 200);
        }, 2400);
    }

    function downloadToastMessage(filename) {
        const name = String(filename || '').toLowerCase();

        if (name.endsWith('.pdf')) {
            return 'PDF 已生成并提交下载';
        }

        if (
            name.endsWith('.png') ||
            name.endsWith('.jpg') ||
            name.endsWith('.jpeg')
        ) {
            return '图片已生成并提交下载';
        }

        return '文件已生成并提交下载';
    }

    function triggerUrlDownload(url, filename, silent) {
        if (!url) return false;

        const link = document.createElement('a');
        link.href = url;
        link.download = filename;

        document.body.appendChild(link);
        link.click();
        link.remove();

        if (!silent) {
            window.setTimeout(function () {
                showDownloadToast(downloadToastMessage(filename));
            }, 80);
        }

        return true;
    }

    function addImagePreview(url, filename, label, alt) {
        const item = document.createElement('article');

        item.innerHTML =
            '<div><strong></strong><a>保存图片</a></div><img>';

        item.querySelector('strong').textContent = label;

        const saveLink = item.querySelector('a');
        saveLink.href = url;
        saveLink.download = filename;

        saveLink.addEventListener('click', function (event) {
            event.preventDefault();
            triggerUrlDownload(url, filename);
        });

        const image = item.querySelector('img');
        image.alt = alt;
        image.src = url;

        imageList.appendChild(item);
    }

    function bytesFromText(value) {
        return new TextEncoder().encode(value);
    }

    function concatByteArrays(parts) {
        let length = 0;
        parts.forEach(function (part) { length += part.length; });
        const result = new Uint8Array(length);
        let offset = 0;
        parts.forEach(function (part) {
            result.set(part, offset);
            offset += part.length;
        });
        return result;
    }

    async function jpegPdfImage(canvas) {
        const blob = await canvasToBlob(canvas, 'image/jpeg', 0.9);
        return {
            width: canvas.width,
            height: canvas.height,
            bytes: new Uint8Array(await blob.arrayBuffer())
        };
    }

    function buildPdfBlob(images) {
        const objectCount = 2 + images.length * 3;
        const objects = new Array(objectCount);
        const kids = [];

        objects[0] = [bytesFromText('<< /Type /Catalog /Pages 2 0 R >>')];

        images.forEach(function (image, index) {
            const pageNumber = 3 + index * 3;
            const imageNumber = pageNumber + 1;
            const contentNumber = pageNumber + 2;
            kids.push(pageNumber + ' 0 R');

            const pageWidth = 595.28;
            const pageHeight = Number((pageWidth * image.height / image.width).toFixed(2));
            objects[pageNumber - 1] = [bytesFromText(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + pageWidth + ' ' + pageHeight + '] ' +
                '/Resources << /XObject << /Im0 ' + imageNumber + ' 0 R >> >> ' +
                '/Contents ' + contentNumber + ' 0 R >>'
            )];

            objects[imageNumber - 1] = [
                bytesFromText('<< /Type /XObject /Subtype /Image /Width ' + image.width + ' /Height ' + image.height +
                    ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + image.bytes.length + ' >>\nstream\n'),
                image.bytes,
                bytesFromText('\nendstream')
            ];

            const content = 'q\n' + pageWidth + ' 0 0 ' + pageHeight + ' 0 0 cm\n/Im0 Do\nQ\n';
            objects[contentNumber - 1] = [
                bytesFromText('<< /Length ' + bytesFromText(content).length + ' >>\nstream\n' + content + 'endstream')
            ];
        });

        objects[1] = [bytesFromText('<< /Type /Pages /Kids [' + kids.join(' ') + '] /Count ' + images.length + ' >>')];

        const header = bytesFromText('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n');
        const parts = [header];
        const offsets = [0];
        let byteLength = header.length;

        objects.forEach(function (bodyParts, index) {
            offsets[index + 1] = byteLength;
            const begin = bytesFromText((index + 1) + ' 0 obj\n');
            const end = bytesFromText('\nendobj\n');
            parts.push(begin);
            byteLength += begin.length;
            bodyParts.forEach(function (part) {
                parts.push(part);
                byteLength += part.length;
            });
            parts.push(end);
            byteLength += end.length;
        });

        const xrefOffset = byteLength;
        let xref = 'xref\n0 ' + (objectCount + 1) + '\n0000000000 65535 f \n';
        for (let index = 1; index <= objectCount; index++) {
            xref += String(offsets[index]).padStart(10, '0') + ' 00000 n \n';
        }
        xref += 'trailer\n<< /Size ' + (objectCount + 1) + ' /Root 1 0 R >>\nstartxref\n' + xrefOffset + '\n%%EOF';
        parts.push(bytesFromText(xref));
        return new Blob([concatByteArrays(parts)], {type: 'application/pdf'});
    }

    async function generatePagedImages(rows, token, forPdf) {
        const chunks = splitImageRows(rows);
        const totalPages = chunks.length;
        const pdfImages = [];

        for (let page = 0; page < totalPages; page++) {
            if (token !== imageGeneration) return null;
            const chunk = chunks[page];
            const canvas = makeCanvasPage(chunk, page + 1, totalPages, rows.length);

            if (forPdf) {
                const pdfImage = await jpegPdfImage(canvas);
                pdfImages.push(pdfImage);
                const previewBlob = new Blob([pdfImage.bytes], {type: 'image/jpeg'});
                const previewUrl = URL.createObjectURL(previewBlob);
                imageObjectUrls.push(previewUrl);
                addImagePreview(previewUrl, '阅心-已读历史-' + String(page + 1).padStart(2, '0') + '.jpg',
                    'PDF 第 ' + (page + 1) + ' / ' + totalPages + ' 页 · ' + chunk.length + ' 本',
                    '合并 PDF 第 ' + (page + 1) + ' 页预览');
            } else {
                const blob = await canvasToBlob(canvas, 'image/png');
                const url = URL.createObjectURL(blob);
                imageObjectUrls.push(url);
                const filename = '阅心-已读历史-' + String(page + 1).padStart(2, '0') + '-共' + String(totalPages).padStart(2, '0') + '张.png';
                imagePages.push({url: url, filename: filename});
                addImagePreview(url, filename, '第 ' + (page + 1) + ' / ' + totalPages + ' 张 · ' + chunk.length + ' 本', '已读历史截图第 ' + (page + 1) + ' 张');
            }

            if (imageNote) imageNote.textContent = '正在生成 ' + (page + 1) + ' / ' + totalPages + '；全部 ' + rows.length + ' 本都会包含。';
            await new Promise(function (resolve) { requestAnimationFrame(resolve); });
        }
        return pdfImages;
    }

    async function generateStitchedImage(rows, token) {
        const chunks = splitImageRows(rows);
        const heights = chunks.map(function (chunk) { return canvasPageMetrics(chunk.length).height; });
        const separator = 16;
        const sourceHeight = heights.reduce(function (sum, height) { return sum + height; }, 0) + separator * Math.max(0, chunks.length - 1);
        const sourceWidth = 1440;
        const maxHeight = 24000;
        const maxPixels = 18000000;
        const scale = Math.min(1, maxHeight / sourceHeight, Math.sqrt(maxPixels / (sourceWidth * sourceHeight)));
        const stitched = document.createElement('canvas');
        stitched.width = Math.max(1, Math.floor(sourceWidth * scale));
        stitched.height = Math.max(1, Math.floor(sourceHeight * scale));
        const ctx = stitched.getContext('2d');
        ctx.fillStyle = '#eef2ee';
        ctx.fillRect(0, 0, stitched.width, stitched.height);

        let sourceY = 0;
        for (let page = 0; page < chunks.length; page++) {
            if (token !== imageGeneration) return null;
            const pageCanvas = makeCanvasPage(chunks[page], page + 1, chunks.length, rows.length);
            ctx.drawImage(pageCanvas, 0, Math.floor(sourceY * scale), stitched.width, Math.floor(pageCanvas.height * scale));
            sourceY += pageCanvas.height + separator;
            if (imageNote) imageNote.textContent = '正在拼接 ' + (page + 1) + ' / ' + chunks.length + ' 段…';
            await new Promise(function (resolve) { requestAnimationFrame(resolve); });
        }

        const blob = await canvasToBlob(stitched, 'image/png');
        return {blob: blob, scale: scale, width: stitched.width, height: stitched.height};
    }

    async function showImageExport(rows, mode) {
        if (!imageModal || !imageList) return;
        const token = ++imageGeneration;
        clearImagePages();
        imageRows = rows.slice();
        const selectedMode = ['pages', 'pdf', 'stitch', 'recent'].indexOf(mode) >= 0 ? mode : 'pages';

        openSheet(null);
        imageModal.hidden = false;
        document.body.classList.add('yx-sheet-open');
        if (scrubber) scrubber.classList.add('is-suppressed');

        try {
            if (selectedMode === 'recent') {
                const recentRows = recentImageRows(imageRows);
                if (imageTitle) imageTitle.textContent = '最近书目单图';
                if (imageNote) imageNote.textContent = '正在按完成日期生成最近 ' + recentRows.length + ' 本…';
                const canvas = makeCanvasPage(recentRows, 1, 1, recentRows.length, {
                    title: '阅心 · 最近已读',
                    summary: '最近 ' + recentRows.length + ' 本 · 按完成日期从新到旧 · 每行 3 本'
                });
                const blob = await canvasToBlob(canvas, 'image/png');
                if (token !== imageGeneration) return;
                imageStitchedUrl = URL.createObjectURL(blob);
                imageObjectUrls.push(imageStitchedUrl);
                imageStitchedFilename = '阅心-最近已读-' + recentRows.length + '本-' + new Date().toISOString().slice(0, 10) + '.png';
                addImagePreview(imageStitchedUrl, imageStitchedFilename, '最近 ' + recentRows.length + ' 本 · 单张图片', '最近已读书目截图');
                if (imageDownloadStitched) {
                    imageDownloadStitched.textContent = '下载最近书目单图';
                    imageDownloadStitched.hidden = false;
                }
                if (imageNote) imageNote.textContent = '已生成最近 ' + recentRows.length + ' 本。容量上限为一张标准图 84 本，超出的较旧记录未包含。';
                return;
            }

            if (selectedMode === 'stitch') {
                if (imageTitle) imageTitle.textContent = '完整拼图长图';
                if (imageNote) imageNote.textContent = '正在把全部分页纵向拼成一张长图…';
                const result = await generateStitchedImage(imageRows, token);
                if (!result || token !== imageGeneration) return;
                imageStitchedUrl = URL.createObjectURL(result.blob);
                imageObjectUrls.push(imageStitchedUrl);
                imageStitchedFilename = '阅心-已读历史-完整拼图-' + imageRows.length + '本.png';
                addImagePreview(imageStitchedUrl, imageStitchedFilename, '完整拼图 · ' + imageRows.length + ' 本', '已读历史完整拼图长图');
                if (imageDownloadStitched) {
                    imageDownloadStitched.textContent = '下载拼图长图';
                    imageDownloadStitched.hidden = false;
                }
                const scaled = result.scale < 0.999 ? '；为兼容手机，长图已等比缩放到 ' + result.width + ' × ' + result.height : '';
                if (imageNote) imageNote.textContent = '已把全部 ' + imageRows.length + ' 本拼成一张长图' + scaled + '。';
                return;
            }

            if (selectedMode === 'pdf') {
                if (imageTitle) imageTitle.textContent = '完整合并 PDF';
                if (imageNote) imageNote.textContent = '正在生成多页 PDF…';
                const pdfImages = await generatePagedImages(imageRows, token, true);
                if (!pdfImages || token !== imageGeneration) return;
                const pdfBlob = buildPdfBlob(pdfImages);
                imagePdfUrl = URL.createObjectURL(pdfBlob);
                imageObjectUrls.push(imagePdfUrl);
                imagePdfFilename = '阅心-已读历史-完整-' + imageRows.length + '本.pdf';
                if (imageDownloadPdf) imageDownloadPdf.hidden = false;
                if (imageNote) imageNote.textContent = '已生成完整 PDF：共 ' + pdfImages.length + ' 页，包含全部 ' + imageRows.length + ' 本。';
                return;
            }

            if (imageTitle) imageTitle.textContent = '分页 PNG';
            if (imageNote) imageNote.textContent = '正在生成分页图片…';
            await generatePagedImages(imageRows, token, false);
            if (token !== imageGeneration) return;
            if (imageDownloadAll) imageDownloadAll.hidden = false;
            if (imageNote) imageNote.textContent = '已完整生成 ' + imageRows.length + ' 本，共 ' + imagePages.length + ' 张。每行 3 本，仅含序号、书名、作者、出版社、国籍和完成日期。';
        } catch (error) {
            if (imageNote) imageNote.textContent = error && error.message ? error.message : '导出生成失败，请减少范围后重试。';
        }
    }

    if (imageDownloadAll) imageDownloadAll.addEventListener('click', async function () {
        if (!imagePages.length) return;
        imageDownloadAll.disabled = true;
        const oldText = imageDownloadAll.textContent;
        imageDownloadAll.textContent = '正在下载 ' + imagePages.length + ' 张';
        for (let index = 0; index < imagePages.length; index++) {
            triggerUrlDownload(imagePages[index].url, imagePages[index].filename, true);
            await new Promise(function (resolve) { setTimeout(resolve, 320); });
        }
        imageDownloadAll.textContent = oldText;
        imageDownloadAll.disabled = false;

        showDownloadToast(
            '全部 ' +
            imagePages.length +
            ' 张图片已生成并提交下载'
        );
    });

    if (imageDownloadPdf) imageDownloadPdf.addEventListener('click', function () {
        triggerUrlDownload(imagePdfUrl, imagePdfFilename);
    });

    if (imageDownloadStitched) imageDownloadStitched.addEventListener('click', function () {
        triggerUrlDownload(imageStitchedUrl, imageStitchedFilename);
    });

    const imageButton = document.getElementById('yxExportRecordsImage');
    if (imageButton) imageButton.addEventListener('click', async function () {
        const rows = requireExportRows();
        if (!rows) return;
        imageButton.disabled = true;
        const oldText = imageButton.textContent;
        imageButton.textContent = '生成中';
        await showImageExport(rows, imageMode ? imageMode.value : 'pages');
        imageButton.textContent = oldText;
        imageButton.disabled = false;
    });

    if (imageModal) imageModal.addEventListener('click', function (event) {
        if (event.target === imageModal || event.target.closest('[data-yx-image-close]')) {
            imageGeneration += 1;
            imageModal.hidden = true;
            document.body.classList.remove('yx-sheet-open');
            if (scrubber) scrubber.classList.remove('is-suppressed');
            clearImagePages();
        }
    });

    /* YUEXIN_TABLE_VERTICAL_SCRUBBER_V17 */

    function activeScrubberItems() {
        return root.dataset.view === 'table'
            ? tableRows
            : cards;
    }

    function updateScrubberVisibility() {
        if (!scrubber) return;

        const mobile = window.matchMedia(
            '(max-width: 820px)'
        ).matches;

        const items = activeScrubberItems();

        scrubber.hidden = !(
            mobile &&
            items.length > 8
        );

        if (!scrubber.hidden) {
            window.requestAnimationFrame(function () {
                updateScrubber();
            });
        }
    }

    if (
        scrubber &&
        (cards.length || tableRows.length)
    ) {
        const track = scrubber.querySelector(
            '.yx-vertical-track'
        );

        const thumb = scrubber.querySelector(
            '.yx-vertical-thumb'
        );

        const bubble = scrubber.querySelector(
            '.yx-vertical-bubble'
        );

        let dragging = false;
        let activeTouchId = null;
        let frameId = 0;

        function currentItems() {
            return activeScrubberItems().filter(
                function (item) {
                    return (
                        !item.hidden &&
                        item.getClientRects().length > 0
                    );
                }
            );
        }

        function listMetrics(items) {
            if (!items.length) {
                return {
                    start: 0,
                    end: 1
                };
            }

            const firstRect =
                items[0].getBoundingClientRect();

            const lastRect =
                items[items.length - 1]
                    .getBoundingClientRect();

            const first =
                firstRect.top + window.scrollY;

            const last =
                lastRect.bottom + window.scrollY;

            const start = Math.max(
                0,
                first - 88
            );

            const end = Math.max(
                start + 1,
                last - window.innerHeight + 96
            );

            return {
                start: start,
                end: end
            };
        }

        function indexFromScroll(items) {
            if (!items.length) return 0;

            const metrics = listMetrics(items);

            const ratio = Math.max(
                0,
                Math.min(
                    1,
                    (
                        window.scrollY -
                        metrics.start
                    ) /
                    Math.max(
                        1,
                        metrics.end - metrics.start
                    )
                )
            );

            return Math.round(
                ratio * (items.length - 1)
            );
        }

        function itemNumber(item, index) {
            if (!item) {
                return String(index + 1);
            }

            if (
                root.dataset.view === 'table'
            ) {
                const cell = item.cells
                    ? item.cells[0]
                    : item.querySelector('td');

                const value = cell
                    ? cell.textContent.trim()
                    : '';

                return value || String(index + 1);
            }

            const numberNode =
                item.querySelector(
                    '.yx-record-number'
                );

            const value = numberNode
                ? numberNode.textContent
                    .replace(/^#/, '')
                    .trim()
                : '';

            return value || String(index + 1);
        }

        function placeThumb(index, items) {
            if (
                !track ||
                !thumb ||
                !bubble ||
                !items.length
            ) {
                return;
            }

            const safeIndex = Math.max(
                0,
                Math.min(
                    items.length - 1,
                    index
                )
            );

            const travel = Math.max(
                0,
                track.clientHeight -
                thumb.offsetHeight
            );

            const ratio =
                items.length > 1
                    ? safeIndex /
                      (items.length - 1)
                    : 0;

            thumb.style.transform =
                'translate3d(0,' +
                Math.round(travel * ratio) +
                'px,0)';

            bubble.textContent =
                itemNumber(
                    items[safeIndex],
                    safeIndex
                ) +
                ' / ' +
                items.length;
        }

        updateScrubber = function () {
            if (
                frameId ||
                scrubber.hidden ||
                dragging
            ) {
                return;
            }

            frameId = requestAnimationFrame(
                function () {
                    frameId = 0;

                    const items =
                        currentItems();

                    if (!items.length) {
                        scrubber.hidden = true;
                        return;
                    }

                    placeThumb(
                        indexFromScroll(items),
                        items
                    );
                }
            );
        };

        function moveTo(clientY) {
            const items = currentItems();

            if (
                !items.length ||
                !track ||
                !thumb
            ) {
                return;
            }

            const rect =
                track.getBoundingClientRect();

            const travel = Math.max(
                1,
                rect.height -
                thumb.offsetHeight
            );

            const top = Math.max(
                0,
                Math.min(
                    travel,
                    clientY -
                    rect.top -
                    thumb.offsetHeight / 2
                )
            );

            const index = Math.max(
                0,
                Math.min(
                    items.length - 1,
                    Math.round(
                        (
                            top / travel
                        ) *
                        (items.length - 1)
                    )
                )
            );

            placeThumb(index, items);

            const item = items[index];

            if (item) {
                const targetTop =
                    item.getBoundingClientRect().top +
                    window.scrollY -
                    76;

                window.scrollTo({
                    top: Math.max(
                        0,
                        targetTop
                    ),
                    behavior: 'auto'
                });
            }
        }

        if (thumb) {
            thumb.addEventListener(
                'touchstart',
                function (event) {
                    if (
                        !event.changedTouches.length
                    ) {
                        return;
                    }

                    const touch =
                        event.changedTouches[0];

                    activeTouchId =
                        touch.identifier;

                    dragging = true;

                    scrubber.classList.add(
                        'is-dragging'
                    );

                    document.documentElement
                        .classList.add(
                            'yx-scrubber-dragging'
                        );

                    moveTo(touch.clientY);
                    event.preventDefault();
                },
                {
                    passive: false
                }
            );

            thumb.addEventListener(
                'pointerdown',
                function (event) {
                    if (
                        event.pointerType === 'touch'
                    ) {
                        return;
                    }

                    dragging = true;

                    scrubber.classList.add(
                        'is-dragging'
                    );

                    document.documentElement
                        .classList.add(
                            'yx-scrubber-dragging'
                        );

                    moveTo(event.clientY);
                    event.preventDefault();

                    const move = function (
                        moveEvent
                    ) {
                        moveTo(moveEvent.clientY);
                        moveEvent.preventDefault();
                    };

                    const up = function () {
                        dragging = false;

                        scrubber.classList.remove(
                            'is-dragging'
                        );

                        document.documentElement
                            .classList.remove(
                                'yx-scrubber-dragging'
                            );

                        document.removeEventListener(
                            'pointermove',
                            move,
                            true
                        );

                        document.removeEventListener(
                            'pointerup',
                            up,
                            true
                        );

                        updateScrubber();
                    };

                    document.addEventListener(
                        'pointermove',
                        move,
                        {
                            capture: true,
                            passive: false
                        }
                    );

                    document.addEventListener(
                        'pointerup',
                        up,
                        true
                    );
                },
                {
                    passive: false
                }
            );
        }

        document.addEventListener(
            'touchmove',
            function (event) {
                if (
                    !dragging ||
                    activeTouchId === null
                ) {
                    return;
                }

                const touch =
                    Array.from(event.touches)
                        .find(function (item) {
                            return (
                                item.identifier ===
                                activeTouchId
                            );
                        });

                if (!touch) return;

                moveTo(touch.clientY);
                event.preventDefault();
            },
            {
                passive: false,
                capture: true
            }
        );

        function endTouch(event) {
            if (!dragging) return;

            const ended =
                Array.from(
                    event.changedTouches || []
                ).some(function (item) {
                    return (
                        item.identifier ===
                        activeTouchId
                    );
                });

            if (!ended) return;

            dragging = false;
            activeTouchId = null;

            scrubber.classList.remove(
                'is-dragging'
            );

            document.documentElement
                .classList.remove(
                    'yx-scrubber-dragging'
                );

            updateScrubber();
        }

        document.addEventListener(
            'touchend',
            endTouch,
            {
                capture: true
            }
        );

        document.addEventListener(
            'touchcancel',
            endTouch,
            {
                capture: true
            }
        );

        window.addEventListener(
            'scroll',
            updateScrubber,
            {
                passive: true
            }
        );

        window.addEventListener(
            'resize',
            updateScrubberVisibility,
            {
                passive: true
            }
        );
    }

    fillExportSelects();
    updateExportPreview();
    updateScrubberVisibility();

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        openSheet(null);
        closeRecordView();
        if (imageModal && !imageModal.hidden) {
            imageModal.hidden = true;
            document.body.classList.remove('yx-sheet-open');
            if (scrubber) scrubber.classList.remove('is-suppressed');
        }
    });
})();
</script>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
