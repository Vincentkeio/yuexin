<?php
require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();
$uid = (int)$user['id'];
$taskId = (int)($_GET['id'] ?? 0);

function yx_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    );
    exit;
}

if ($taskId <= 0) {
    yx_json_response(['ok' => false, 'message' => '记录参数不正确。'], 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM reading_tasks WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$taskId, $uid]);
$task = $stmt->fetch();

if (!$task) {
    yx_json_response(['ok' => false, 'message' => '记录不存在或无权查看。'], 404);
}

$book = [];
if (!empty($task['book_id'])) {
    $st = $pdo->prepare('SELECT * FROM books WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([(int)$task['book_id'], $uid]);
    $book = $st->fetch() ?: [];
}

$plan = [];
if (!empty($task['plan_id'])) {
    $st = $pdo->prepare('SELECT * FROM reading_plans WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([(int)$task['plan_id'], $uid]);
    $plan = $st->fetch() ?: [];
}

function yx_present($value): bool
{
    if ($value === null) return false;
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') return false;
        if (in_array($value, ['0000-00-00', '0000-00-00 00:00:00', 'null', 'NULL'], true)) return false;
    }
    return true;
}

function yx_first_present(...$values)
{
    foreach ($values as $value) {
        if (yx_present($value)) return $value;
    }
    return null;
}

function yx_reading_period($startDate, $finishDate): array
{
    $startDate = trim((string)$startDate);
    $finishDate = trim((string)$finishDate);
    if (!yx_present($startDate)) $startDate = '';
    if (!yx_present($finishDate)) $finishDate = '';

    if ($startDate === '' && $finishDate === '') return ['', ''];
    if ($startDate === '') return ['完成于 ' . $finishDate, ''];
    if ($finishDate === '') return ['开始于 ' . $startDate, ''];

    $period = $startDate === $finishDate ? $startDate : $startDate . ' 至 ' . $finishDate;
    $days = '';
    try {
        $start = new DateTimeImmutable($startDate);
        $finish = new DateTimeImmutable($finishDate);
        if ($finish >= $start) {
            $days = (string)($start->diff($finish)->days + 1) . ' 天';
        }
    } catch (Throwable $e) {
        $days = '';
    }
    return [$period, $days];
}

function yx_display_value(string $key, $value): string
{
    if (is_array($value) || is_object($value)) {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $text = trim((string)$value);
    $normalizedKey = preg_replace('/^(task_|history_)/', '', $key) ?: $key;

    $maps = [
        'status' => [
            'unread' => '未读', 'reading' => '在读', 'finished' => '已读完',
            'completed' => '已完成', 'unfinished' => '未完成', 'abandoned' => '弃读',
            'not_started' => '未开始', 'active' => '进行中', 'failed' => '未完成',
            'cancelled' => '已取消',
        ],
        'format' => [
            'paper' => '纸质书', 'ebook' => '电子书',
            'audiobook' => '有声书', 'unknown' => '未知',
        ],
        'plan_type' => [
            'weekly' => '周度', 'monthly' => '月度',
            'quarterly' => '季度', 'yearly' => '年度',
        ],
        'rating_level' => [
            'masterpiece' => '神作', 'good' => '好', 'normal' => '一般',
            'bad' => '差', 'abandoned' => '弃读',
        ],
    ];

    if (isset($maps[$normalizedKey][$text])) {
        return $maps[$normalizedKey][$text];
    }

    if (in_array($normalizedKey, ['continue_next', 'is_full', 'is_paper', 'is_ebook', 'is_audiobook', 'is_history_import', 'enabled', 'active'], true)) {
        return ((int)$value === 1) ? '是' : '否';
    }

    if ($normalizedKey === 'progress' && is_numeric($value)) {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.') . '%';
    }

    return $text;
}

$groups = [];
$usedTask = [];
$usedBook = [];
$usedPlan = [];

$add = static function (string $group, string $label, string $key, $value, string $source = '') use (&$groups, &$usedTask, &$usedBook, &$usedPlan): void {
    if (!yx_present($value)) return;
    $groups[$group] ??= [];
    $groups[$group][] = [
        'label' => $label,
        'value' => yx_display_value($key, $value),
        'key' => $key,
    ];
    if ($source === 'task') $usedTask[$key] = true;
    if ($source === 'book') $usedBook[$key] = true;
    if ($source === 'plan') $usedPlan[$key] = true;
};

$title = trim((string)yx_first_present($book['title'] ?? null, $task['raw_book_title'] ?? null, '未命名书籍'));
$author = trim((string)yx_first_present($book['author'] ?? null, $task['raw_author'] ?? null, ''));
$seq = yx_first_present($book['original_index'] ?? null, $task['task_original_index'] ?? null, $task['id']);

$basicBookFields = [
    'original_index' => '编号', 'title' => '书名', 'subtitle' => '副标题',
    'original_title' => '原书名', 'author' => '作者', 'author_country' => '作者国籍',
    'author_country_raw' => '国别原文', 'translator' => '译者',
    'publisher' => '出版社', 'publish_date' => '出版日期', 'publish_year' => '出版年份',
    'isbn' => 'ISBN', 'book_type' => '图书类型', 'tags' => '标签',
    'format' => '阅读形式', 'media_text' => '载体', 'platform' => '阅读平台',
    'is_full' => '是否全本', 'is_paper' => '是否纸质书',
    'is_ebook' => '是否电子书', 'is_audiobook' => '是否有声书',
];
foreach ($basicBookFields as $key => $label) {
    $add('基本信息', $label, $key, $book[$key] ?? null, 'book');
}

$basicTaskFallbacks = [
    'task_original_index' => '编号', 'raw_book_title' => '书名', 'raw_author' => '作者',
    'task_author_country' => '作者国籍', 'task_translator' => '译者',
    'task_publisher' => '出版社', 'task_publish_year' => '出版年份',
    'task_format' => '阅读形式', 'task_media_text' => '载体',
    'task_platform' => '阅读平台', 'task_is_full' => '是否全本',
];
foreach ($basicTaskFallbacks as $key => $label) {
    $bookEquivalent = [
        'task_original_index' => 'original_index', 'raw_book_title' => 'title',
        'raw_author' => 'author', 'task_author_country' => 'author_country',
        'task_translator' => 'translator', 'task_publisher' => 'publisher',
        'task_publish_year' => 'publish_year', 'task_format' => 'format',
        'task_media_text' => 'media_text', 'task_platform' => 'platform',
        'task_is_full' => 'is_full',
    ][$key] ?? null;
    if ($bookEquivalent && yx_present($book[$bookEquivalent] ?? null)) {
        $usedTask[$key] = true;
        continue;
    }
    $add('基本信息', $label, $key, $task[$key] ?? null, 'task');
}

$resolvedStartDate = yx_first_present(
    $task['actual_start_date'] ?? null,
    $book['first_start_date'] ?? null,
    $task['planned_start_date'] ?? null
);
$resolvedFinishDate = yx_first_present(
    $task['actual_finish_date'] ?? null,
    $book['final_finish_date'] ?? null,
    $task['planned_finish_date'] ?? null
);
[$readingPeriod, $readingDays] = yx_reading_period($resolvedStartDate, $resolvedFinishDate);
$add('阅读记录', '阅读时间', 'reading_period', $readingPeriod);
$add('阅读记录', '阅读时长', 'reading_days', $readingDays);

$readingFields = [
    'planned_start_date' => '计划开始日期', 'planned_finish_date' => '计划完成日期',
    'actual_start_date' => '实际开始日期', 'actual_finish_date' => '实际完成日期',
    'status' => '阅读状态', 'progress' => '阅读进度',
    'continue_next' => '延续到下期计划',
];
foreach ($readingFields as $key => $label) {
    $add('阅读记录', $label, $key, $task[$key] ?? null, 'task');
}

$bookReadingFields = [
    'first_start_date' => '首次开始日期', 'final_finish_date' => '最终完成日期',
    'status' => '书籍状态', 'progress' => '书籍进度',
];
foreach ($bookReadingFields as $key => $label) {
    $add('阅读记录', $label, $key, $book[$key] ?? null, 'book');
}

$reviewTaskFields = [
    'rating_score' => '本次评分', 'rating_level' => '本次评价等级', 'comment' => '阅读备注',
];
foreach ($reviewTaskFields as $key => $label) {
    $add('评价与笔记', $label, $key, $task[$key] ?? null, 'task');
}

$reviewBookFields = [
    'rating_score' => '个人评分', 'self_rating_note' => '自评备注',
    'rating_level' => '评价等级', 'short_review' => '短评', 'notes' => '笔记',
];
foreach ($reviewBookFields as $key => $label) {
    $add('评价与笔记', $label, $key, $book[$key] ?? null, 'book');
}

$planFields = [
    'name' => '计划名称', 'plan_type' => '计划类型', 'start_date' => '计划开始日期',
    'end_date' => '计划结束日期', 'target_count' => '目标数量',
    'description' => '计划说明', 'status' => '计划状态',
];
foreach ($planFields as $key => $label) {
    $add('所属计划', $label, $key, $plan[$key] ?? null, 'plan');
}

$linkFields = [
    'cover_url' => '封面链接',
    'douban_cover_url' => '豆瓣封面链接',
    'douban_url' => '豆瓣链接',
];
foreach ($linkFields as $key => $label) {
    $add('链接信息', $label, $key, $book[$key] ?? null, 'book');
}

$doubanFields = [
    'douban_subject_id' => '豆瓣条目 ID', 'douban_title' => '豆瓣书名',
    'douban_author' => '豆瓣作者', 'douban_publisher' => '豆瓣出版社',
    'douban_publish_date' => '豆瓣出版日期', 'douban_rating' => '豆瓣评分',
    'douban_rating_count' => '豆瓣评分人数', 'douban_read_count' => '豆瓣读过人数',
    'douban_wish_count' => '豆瓣想读人数', 'douban_summary' => '豆瓣简介',
    'douban_updated_at' => '豆瓣更新时间',
];
foreach ($doubanFields as $key => $label) {
    $add('豆瓣信息', $label, $key, $book[$key] ?? null, 'book');
}

$systemTaskFields = ['created_at' => '记录创建时间', 'updated_at' => '记录更新时间'];
foreach ($systemTaskFields as $key => $label) {
    $add('时间信息', $label, $key, $task[$key] ?? null, 'task');
}
$systemBookFields = ['created_at' => '书籍创建时间', 'updated_at' => '书籍更新时间'];
foreach ($systemBookFields as $key => $label) {
    $add('时间信息', $label, $key, $book[$key] ?? null, 'book');
}

$excluded = [
    'id', 'user_id', 'book_id', 'plan_id', 'password_hash',
];

$unknownLabels = [
    'original_title' => '原书名', 'author_country_raw' => '国别原文',
    'media_text' => '载体', 'is_paper' => '是否纸质书',
    'is_ebook' => '是否电子书', 'is_audiobook' => '是否有声书',
    'cover_url' => '封面链接', 'douban_cover_url' => '豆瓣封面链接',
    'task_media_text' => '任务载体', 'task_publish_year' => '任务出版年份',
    'task_translator' => '任务译者', 'task_is_full' => '任务是否全本',
];

$appendUnknown = static function (array $source, string $sourceName, array $used) use (&$groups, $excluded, $unknownLabels): void {
    foreach ($source as $key => $value) {
        if (isset($used[$key]) || in_array($key, $excluded, true) || !yx_present($value)) continue;
        $label = $unknownLabels[$key] ?? str_replace('_', ' ', $key);
        $display = yx_display_value($key, $value);
        if (!yx_present($display)) continue;
        $groups['其他信息'] ??= [];
        $groups['其他信息'][] = [
            'label' => $sourceName . ' · ' . $label,
            'value' => $display,
            'key' => $key,
        ];
    }
};

$appendUnknown($task, '阅读记录', $usedTask);
$appendUnknown($book, '书籍', $usedBook);
$appendUnknown($plan, '计划', $usedPlan);

$groups = array_values(array_filter(array_map(
    static fn(string $name, array $items): array => ['name' => $name, 'items' => $items],
    array_keys($groups),
    array_values($groups)
), static fn(array $group): bool => !empty($group['items'])));

yx_json_response([
    'ok' => true,
    'record' => [
        'task_id' => $taskId,
        'number' => (string)$seq,
        'title' => $title,
        'author' => $author,
        'edit_url' => 'record_edit.php?id=' . $taskId,
        'groups' => $groups,
    ],
]);
