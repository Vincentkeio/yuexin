<?php
require_once __DIR__ . '/../app/bootstrap.php';

$u = require_login();
$uid = (int)$u['id'];
$pdo = db();

function yx_has_col(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $col]);

    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

function yx_null($v)
{
    $v = is_string($v) ? trim($v) : $v;
    return $v === '' ? null : $v;
}

function yx_num($v)
{
    $v = trim((string)$v);
    return $v === '' || !is_numeric($v) ? null : $v;
}

function yx_subject_id_from_url(string $url): ?string
{
    if (preg_match('~book\.douban\.com/subject/(\d+)~i', $url, $m)) {
        return $m[1];
    }
    return null;
}

function yx_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $cols = [];
    $vals = [];

    foreach ($data as $k => $v) {
        if (yx_has_col($pdo, $table, $k)) {
            $cols[] = "`$k`";
            $vals[] = $v;
        }
    }

    if (!$cols) {
        throw new RuntimeException("没有可写入字段：{$table}");
    }

    $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);

    return (int)$pdo->lastInsertId();
}

function yx_get_manual_history_plan(PDO $pdo, int $uid): int
{
    /*
     * 注意：
     * 不要在这里 ALTER TABLE。
     * MySQL/MariaDB 的 DDL 会隐式提交事务，导致后面的 commit() 报：
     * There is no active transaction
     */

    $stmt = $pdo->prepare("
        SELECT id
        FROM reading_plans
        WHERE user_id = ?
          AND name = '手动阅读历史'
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([$uid]);

    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }

    $data = [
        'user_id' => $uid,
        'name' => '手动阅读历史',
        'plan_type' => 'yearly',
        'start_date' => date('Y-01-01'),
        'end_date' => date('Y-12-31'),
        'target_count' => 0,
        'description' => '手动新增阅读历史生成的隐藏容器，不计入阅读计划。',
        'status' => 'completed',
        'is_history_import' => 1,
    ];

    return yx_insert_dynamic($pdo, 'reading_plans', $data);
}

function yx_next_original_index(PDO $pdo, int $uid): int
{
    if (!yx_has_col($pdo, 'books', 'original_index')) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(CAST(NULLIF(original_index, '') AS UNSIGNED)), 0) + 1
        FROM books
        WHERE user_id = ?
    ");
    $stmt->execute([$uid]);

    return (int)$stmt->fetchColumn();
}

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            throw new RuntimeException('书名不能为空');
        }

        $format = trim($_POST['format'] ?? 'unknown');
        if ($format === '') {
            $format = 'unknown';
        }

        $startDate = yx_null($_POST['start_date'] ?? '');
        $finishDate = yx_null($_POST['finish_date'] ?? '');

        $ratingScore = yx_num($_POST['rating_score'] ?? '');
        $doubanRating = yx_num($_POST['douban_rating'] ?? '');
        $doubanRead = yx_num($_POST['douban_read_count'] ?? '');
        $doubanUrl = yx_null($_POST['douban_url'] ?? '');
        $doubanSubjectId = $doubanUrl ? yx_subject_id_from_url($doubanUrl) : null;

        $pdo->beginTransaction();

        $seq = yx_next_original_index($pdo, $uid);

        $bookData = [
            'user_id' => $uid,
            'original_index' => $seq ?: null,
            'title' => $title,
            'author' => yx_null($_POST['author'] ?? ''),
            'author_country' => yx_null($_POST['author_country'] ?? ''),
            'publisher' => yx_null($_POST['publisher'] ?? ''),
            'publish_date' => yx_null($_POST['publish_date'] ?? ''),
            'isbn' => yx_null($_POST['isbn'] ?? ''),
            'book_type' => yx_null($_POST['book_type'] ?? ''),
            'tags' => yx_null($_POST['tags'] ?? ''),
            'format' => $format,
            'platform' => yx_null($_POST['platform'] ?? ''),
            'status' => 'finished',
            'progress' => 100,
            'first_start_date' => $startDate,
            'final_finish_date' => $finishDate,
            'rating_score' => $ratingScore,
            'rating_level' => yx_null($_POST['rating_level'] ?? ''),
            'short_review' => yx_null($_POST['short_review'] ?? ''),
            'notes' => yx_null($_POST['notes'] ?? ''),
            'douban_subject_id' => $doubanSubjectId,
            'douban_url' => $doubanUrl,
            'douban_rating' => $doubanRating,
            'douban_read_count' => $doubanRead,
            'douban_updated_at' => ($doubanUrl || $doubanRating || $doubanRead) ? date('Y-m-d H:i:s') : null,
        ];

        // 根据阅读形式同步布尔字段
        $bookData['is_paper'] = $format === 'paper' ? 1 : 0;
        $bookData['is_ebook'] = $format === 'ebook' ? 1 : 0;
        $bookData['is_audiobook'] = $format === 'audiobook' ? 1 : 0;

        $bookId = yx_insert_dynamic($pdo, 'books', $bookData);

        $planId = yx_get_manual_history_plan($pdo, $uid);

        $taskData = [
            'user_id' => $uid,
            'plan_id' => $planId,
            'book_id' => $bookId,
            'raw_book_title' => $title,
            'raw_author' => yx_null($_POST['author'] ?? ''),
            'task_original_index' => $seq ?: null,
            'task_format' => $format === 'unknown' ? null : $format,
            'task_platform' => yx_null($_POST['platform'] ?? ''),
            'task_author_country' => yx_null($_POST['author_country'] ?? ''),
            'task_publisher' => yx_null($_POST['publisher'] ?? ''),
            'planned_start_date' => $startDate,
            'planned_finish_date' => $finishDate,
            'actual_start_date' => $startDate,
            'actual_finish_date' => $finishDate,
            'status' => 'completed',
            'progress' => 100,
            'continue_next' => 0,
            'rating_score' => $ratingScore,
            'rating_level' => yx_null($_POST['rating_level'] ?? ''),
            'comment' => yx_null($_POST['comment'] ?? ''),
        ];

        $taskId = yx_insert_dynamic($pdo, 'reading_tasks', $taskData);

        if (yx_has_col($pdo, 'reading_progress_logs', 'user_id')) {
            try {
                yx_insert_dynamic($pdo, 'reading_progress_logs', [
                    'user_id' => $uid,
                    'task_id' => $taskId,
                    'book_id' => $bookId,
                    'progress' => 100,
                    'status' => 'completed',
                    'log_date' => $finishDate ?: date('Y-m-d'),
                    'note' => '手动新增阅读历史',
                ]);
            } catch (Throwable $e) {
                // 日志失败不影响主流程
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        $_SESSION['flash'] = '已增加书籍，并写入阅读历史。';
        redirect('records.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $err = $e->getMessage();
    }
}

$title = '增加书籍';
include __DIR__ . '/../app/layout/header.php';
?>

<div class="card">
    <p class="muted">手动录入一本已经完成阅读的书。保存后会自动进入阅读历史，状态为已完成，进度为 100。</p>

    <?php if ($err): ?>
        <div class="alert danger"><?= e($err) ?></div>
    <?php endif; ?>
</div>

<form method="post">
    <?= csrf_field() ?>

    <div class="card">
        <h2>基础信息</h2>
        <div class="form-grid">
            <div>
                <label>书名 *</label>
                <input name="title" required>
            </div>

            <div>
                <label>作者</label>
                <input name="author">
            </div>

            <div>
                <label>作者国籍</label>
                <input name="author_country">
            </div>

            <div>
                <label>出版社</label>
                <input name="publisher">
            </div>

            <div>
                <label>出版日期</label>
                <input name="publish_date" placeholder="例如 2018-01 或 2018">
            </div>

            <div>
                <label>ISBN</label>
                <input name="isbn">
            </div>

            <div>
                <label>书籍类型</label>
                <input name="book_type" placeholder="小说 / 历史 / 哲学等">
            </div>

            <div>
                <label>标签</label>
                <input name="tags" placeholder="多个标签用逗号分隔">
            </div>
        </div>
    </div>

    <div class="card">
        <h2>阅读信息</h2>
        <div class="form-grid">
            <div>
                <label>阅读形式</label>
                <select name="format">
                    <option value="unknown">未选择</option>
                    <option value="paper">纸质书</option>
                    <option value="ebook">电子书</option>
                    <option value="audiobook">有声书</option>
                </select>
            </div>

            <div>
                <label>阅读平台</label>
                <input name="platform" placeholder="Kindle / 微信读书 / 纸质书等">
            </div>

            <div>
                <label>开始阅读日期</label>
                <input type="date" name="start_date">
            </div>

            <div>
                <label>完成阅读日期</label>
                <input type="date" name="finish_date">
            </div>

            <div>
                <label>自我评分</label>
                <input type="number" step="0.1" min="0" max="10" name="rating_score">
            </div>

            <div>
                <label>我的等级</label>
                <select name="rating_level">
                    <option value="">未评</option>
                    <option value="masterpiece">神作</option>
                    <option value="good">好书</option>
                    <option value="normal">一般</option>
                    <option value="bad">烂书</option>
                    <option value="abandoned">弃读</option>
                </select>
            </div>
        </div>

        <p>
            <label>短评</label>
            <textarea name="short_review"></textarea>
        </p>

        <p>
            <label>备注 / 阅读历史备注</label>
            <textarea name="comment"></textarea>
        </p>

        <p>
            <label>笔记</label>
            <textarea name="notes"></textarea>
        </p>
    </div>

    <div class="card">
        <h2>豆瓣信息，可选</h2>
        <p class="muted">如果现在没有豆瓣信息，可以留空。之后仍可使用“豆瓣信息 CSV 导入”或“一键获取豆瓣信息”。</p>

        <div class="form-grid">
            <div>
                <label>豆瓣 URL</label>
                <input name="douban_url" placeholder="https://book.douban.com/subject/xxxxxxx/">
            </div>

            <div>
                <label>豆瓣评分</label>
                <input type="number" step="0.1" min="0" max="10" name="douban_rating">
            </div>

            <div>
                <label>豆瓣阅读人数</label>
                <input type="number" min="0" name="douban_read_count">
            </div>
        </div>
    </div>

    <div class="card actions">
        <button>保存并加入阅读历史</button>
        <a class="btn ghost" href="records.php">返回阅读历史</a>
    </div>
</form>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
