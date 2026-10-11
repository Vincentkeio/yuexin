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

function yx_subject_id_from_url(?string $url): ?string
{
    $url = trim((string)$url);
    if ($url !== '' && preg_match('~book\.douban\.com/subject/(\d+)~i', $url, $m)) {
        return $m[1];
    }
    return null;
}

function yx_update_dynamic(PDO $pdo, string $table, array $data, string $where, array $whereParams): void
{
    $sets = [];
    $params = [];

    foreach ($data as $k => $v) {
        if (yx_has_col($pdo, $table, $k)) {
            $sets[] = "`$k` = ?";
            $params[] = $v;
        }
    }

    if (!$sets) {
        return;
    }

    $sql = "UPDATE `$table` SET " . implode(',', $sets) . " WHERE " . $where;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $whereParams));
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    die('缺少阅读历史 ID');
}

$stmt = $pdo->prepare('
    SELECT
        t.*,
        b.id AS book_id,
        b.original_index,
        b.title,
        b.author,
        b.author_country,
        b.publisher,
        b.publish_date,
        b.isbn,
        b.book_type,
        b.tags,
        b.format,
        b.platform,
        b.first_start_date,
        b.final_finish_date,
        b.rating_score AS book_rating_score,
        b.rating_level AS book_rating_level,
        b.short_review,
        b.notes,
        b.douban_subject_id,
        b.douban_url,
        b.douban_rating,
        b.douban_rating_count,
        b.douban_read_count
    FROM reading_tasks t
    LEFT JOIN books b ON b.id = t.book_id
    WHERE t.id = ?
      AND t.user_id = ?
    LIMIT 1
');
$stmt->execute([$id, $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die('阅读历史不存在或无权访问');
}

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'delete') {
            $bookId = (int)($row['book_id'] ?? 0);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('DELETE FROM reading_tasks WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $uid]);

            if ($bookId > 0) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM reading_tasks WHERE book_id = ? AND user_id = ?');
                $stmt->execute([$bookId, $uid]);
                $stillUsed = (int)$stmt->fetchColumn();

                if ($stillUsed === 0) {
                    $stmt = $pdo->prepare('DELETE FROM books WHERE id = ? AND user_id = ?');
                    $stmt->execute([$bookId, $uid]);
                }
            }

            $pdo->commit();

            $_SESSION['flash'] = '已删除该书及对应阅读历史。';
            redirect('records.php');
        }
        $bookId = (int)($row['book_id'] ?? 0);

        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            throw new RuntimeException('书名不能为空');
        }

        $format = trim($_POST['format'] ?? 'unknown');
        if ($format === '') {
            $format = 'unknown';
        }

        $seq = yx_null($_POST['original_index'] ?? '');
        $author = yx_null($_POST['author'] ?? '');
        $country = yx_null($_POST['author_country'] ?? '');
        $publisher = yx_null($_POST['publisher'] ?? '');
        $publishDate = yx_null($_POST['publish_date'] ?? '');
        $isbn = yx_null($_POST['isbn'] ?? '');
        $bookType = yx_null($_POST['book_type'] ?? '');
        $tags = yx_null($_POST['tags'] ?? '');
        $platform = yx_null($_POST['platform'] ?? '');
        $startDate = yx_null($_POST['start_date'] ?? '');
        $finishDate = yx_null($_POST['finish_date'] ?? '');
        $ratingScore = yx_num($_POST['rating_score'] ?? '');
        $ratingLevel = yx_null($_POST['rating_level'] ?? '');
        $shortReview = yx_null($_POST['short_review'] ?? '');
        $notes = yx_null($_POST['notes'] ?? '');
        $comment = yx_null($_POST['comment'] ?? '');

        $doubanUrl = yx_null($_POST['douban_url'] ?? '');
        $doubanSubjectId = yx_subject_id_from_url($doubanUrl);
        $doubanRating = yx_num($_POST['douban_rating'] ?? '');
        $doubanRead = yx_num($_POST['douban_read_count'] ?? '');
        $doubanRatingCount = yx_num($_POST['douban_rating_count'] ?? '');

        $pdo->beginTransaction();

        if ($bookId > 0) {
            $bookData = [
                'original_index' => $seq,
                'title' => $title,
                'author' => $author,
                'author_country' => $country,
                'publisher' => $publisher,
                'publish_date' => $publishDate,
                'isbn' => $isbn,
                'book_type' => $bookType,
                'tags' => $tags,
                'format' => $format,
                'platform' => $platform,
                'status' => 'finished',
                'progress' => 100,
                'first_start_date' => $startDate,
                'final_finish_date' => $finishDate,
                'rating_score' => $ratingScore,
                'rating_level' => $ratingLevel,
                'short_review' => $shortReview,
                'notes' => $notes,
                'douban_subject_id' => $doubanSubjectId ?: ($row['douban_subject_id'] ?? null),
                'douban_url' => $doubanUrl,
                'douban_rating' => $doubanRating,
                'douban_rating_count' => $doubanRatingCount,
                'douban_read_count' => $doubanRead,
                'douban_updated_at' => ($doubanUrl || $doubanRating || $doubanRatingCount || $doubanRead) ? date('Y-m-d H:i:s') : ($row['douban_updated_at'] ?? null),
                'is_paper' => $format === 'paper' ? 1 : 0,
                'is_ebook' => $format === 'ebook' ? 1 : 0,
                'is_audiobook' => $format === 'audiobook' ? 1 : 0,
            ];

            yx_update_dynamic($pdo, 'books', $bookData, 'id = ? AND user_id = ?', [$bookId, $uid]);
        }

        $taskData = [
            'raw_book_title' => $title,
            'raw_author' => $author,
            'task_original_index' => $seq,
            'task_format' => $format === 'unknown' ? null : $format,
            'task_platform' => $platform,
            'task_author_country' => $country,
            'task_publisher' => $publisher,
            'planned_start_date' => $startDate,
            'planned_finish_date' => $finishDate,
            'actual_start_date' => $startDate,
            'actual_finish_date' => $finishDate,
            'status' => 'completed',
            'progress' => 100,
            'rating_score' => $ratingScore,
            'rating_level' => $ratingLevel,
            'comment' => $comment,
        ];

        yx_update_dynamic($pdo, 'reading_tasks', $taskData, 'id = ? AND user_id = ?', [$id, $uid]);

        $pdo->commit();

        $_SESSION['flash'] = '阅读历史条目已更新。';
        redirect('records.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $err = $e->getMessage();
    }
}

function val(array $row, string $key, $fallback = '')
{
    return $row[$key] ?? $fallback;
}

$seq = val($row, 'original_index') ?: val($row, 'task_original_index');
$titleValue = val($row, 'title') ?: val($row, 'raw_book_title');
$authorValue = val($row, 'author') ?: val($row, 'raw_author');
$countryValue = val($row, 'author_country') ?: val($row, 'task_author_country');
$publisherValue = val($row, 'publisher') ?: val($row, 'task_publisher');
$formatValue = val($row, 'format') ?: val($row, 'task_format') ?: 'unknown';
$platformValue = val($row, 'platform') ?: val($row, 'task_platform');
$startValue = val($row, 'actual_start_date') ?: val($row, 'first_start_date') ?: val($row, 'planned_start_date');
$finishValue = val($row, 'actual_finish_date') ?: val($row, 'final_finish_date') ?: val($row, 'planned_finish_date');
$ratingValue = val($row, 'book_rating_score') ?: val($row, 'rating_score');

$title = '编辑阅读历史';
include __DIR__ . '/../app/layout/header.php';
?>

<div class="card">
    <p class="muted">修改后会同步更新书籍信息和阅读历史信息。</p>

    <?php if ($err): ?>
        <div class="alert danger"><?= e($err) ?></div>
    <?php endif; ?>
</div>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e($id) ?>">
    <input type="hidden" name="action" value="save">

    <div class="card">
        <h2>基础信息</h2>
        <div class="form-grid">
            <div>
                <label>序号</label>
                <input name="original_index" value="<?= e($seq) ?>">
            </div>

            <div>
                <label>书名 *</label>
                <input name="title" value="<?= e($titleValue) ?>" required>
            </div>

            <div>
                <label>作者</label>
                <input name="author" value="<?= e($authorValue) ?>">
            </div>

            <div>
                <label>作者国籍</label>
                <input name="author_country" value="<?= e($countryValue) ?>">
            </div>

            <div>
                <label>出版社</label>
                <input name="publisher" value="<?= e($publisherValue) ?>">
            </div>

            <div>
                <label>出版日期</label>
                <input name="publish_date" value="<?= e(val($row, 'publish_date')) ?>">
            </div>

            <div>
                <label>ISBN</label>
                <input name="isbn" value="<?= e(val($row, 'isbn')) ?>">
            </div>

            <div>
                <label>书籍类型</label>
                <input name="book_type" value="<?= e(val($row, 'book_type')) ?>">
            </div>

            <div>
                <label>标签</label>
                <input name="tags" value="<?= e(val($row, 'tags')) ?>">
            </div>
        </div>
    </div>

    <div class="card">
        <h2>阅读信息</h2>
        <div class="form-grid">
            <div>
                <label>阅读形式</label>
                <select name="format">
                    <?php foreach (['unknown'=>'未选择','paper'=>'纸质书','ebook'=>'电子书','audiobook'=>'有声书'] as $k=>$v): ?>
                        <option value="<?= e($k) ?>" <?= $formatValue === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>阅读平台</label>
                <input name="platform" value="<?= e($platformValue) ?>">
            </div>

            <div>
                <label>开始阅读日期</label>
                <input type="date" name="start_date" value="<?= e($startValue) ?>">
            </div>

            <div>
                <label>完成阅读日期</label>
                <input type="date" name="finish_date" value="<?= e($finishValue) ?>">
            </div>

            <div>
                <label>自我评分</label>
                <input type="number" step="0.1" min="0" max="10" name="rating_score" value="<?= e($ratingValue) ?>">
            </div>

            <div>
                <label>我的等级</label>
                <?php $level = val($row, 'book_rating_level') ?: val($row, 'rating_level'); ?>
                <select name="rating_level">
                    <option value="">未评</option>
                    <?php foreach (['masterpiece'=>'神作','good'=>'好书','normal'=>'一般','bad'=>'烂书','abandoned'=>'弃读'] as $k=>$v): ?>
                        <option value="<?= e($k) ?>" <?= $level === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <p>
            <label>短评</label>
            <textarea name="short_review"><?= e(val($row, 'short_review')) ?></textarea>
        </p>

        <p>
            <label>阅读历史备注</label>
            <textarea name="comment"><?= e(val($row, 'comment')) ?></textarea>
        </p>

        <p>
            <label>笔记</label>
            <textarea name="notes"><?= e(val($row, 'notes')) ?></textarea>
        </p>
    </div>

    <div class="card">
        <h2>豆瓣信息</h2>
        <div class="form-grid">
            <div>
                <label>豆瓣 URL</label>
                <input name="douban_url" value="<?= e(val($row, 'douban_url')) ?>">
            </div>

            <div>
                <label>豆瓣评分</label>
                <input type="number" step="0.1" min="0" max="10" name="douban_rating" value="<?= e(val($row, 'douban_rating')) ?>">
            </div>

            <div>
                <label>豆瓣阅读人数</label>
                <input type="number" min="0" name="douban_read_count" value="<?= e(val($row, 'douban_read_count')) ?>">
            </div>

            <div>
                <label>豆瓣评价人数</label>
                <input type="number" min="0" name="douban_rating_count" value="<?= e(val($row, 'douban_rating_count')) ?>">
            </div>
        </div>
    </div>

    <div class="card actions">
        <button>保存修改</button>
        <a class="btn ghost" href="records.php">返回阅读历史</a>
    </div>
</form>

<div class="card danger-zone">
    <h2>危险操作</h2>
    <p class="muted">删除后会移除这条阅读历史。如果这本书没有被其他阅读历史引用，也会同时从书库中删除。</p>

    <form method="post" onsubmit="return confirm('确定删除这本书吗？此操作不可恢复。');">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="danger">删除本书</button>
    </form>
</div>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
