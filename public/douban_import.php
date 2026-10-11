<?php
require_once __DIR__ . '/../app/bootstrap.php';

$u = require_login();
$uid = (int)$u['id'];
$pdo = db();

$msg = null;
$err = null;

function csv_pick(array $row, array $keys): string
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && trim((string)$row[$k]) !== '') {
            return trim((string)$row[$k]);
        }
    }
    return '';
}

function subject_id_from_url(string $url): string
{
    if (preg_match('~book\.douban\.com/subject/(\d+)~i', $url, $m)) {
        return $m[1];
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        if (empty($_FILES['csv']['tmp_name'])) {
            throw new RuntimeException('请上传 CSV 文件');
        }

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            throw new RuntimeException('无法读取 CSV 文件');
        }

        $header = fgetcsv($fh, 0, ',', '"', '\\');
        if (!$header) {
            throw new RuntimeException('CSV 表头为空');
        }

        // 去 BOM
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        $updated = 0;
        $skipped = 0;
        $line = 1;

        while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $line++;

            if (count(array_filter($cols, fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }

            $row = [];
            foreach ($header as $i => $name) {
                $row[trim((string)$name)] = $cols[$i] ?? '';
            }

            $seq = csv_pick($row, ['序号', '原始序号', '编号']);
            $title = csv_pick($row, ['书名', '标题']);
            $url = csv_pick($row, ['豆瓣URL', '豆瓣链接', 'douban_url']);
            $rating = csv_pick($row, ['豆瓣评分', 'douban_rating']);
            $readCount = csv_pick($row, ['豆瓣阅读人数', '豆瓣读过人数', 'douban_read_count']);
            $ratingCount = csv_pick($row, ['豆瓣评价人数', '豆瓣评分人数', 'douban_rating_count']);

            if ($seq === '' && $title === '') {
                $skipped++;
                continue;
            }

            if ($seq !== '') {
                $stmt = $pdo->prepare('SELECT id FROM books WHERE user_id=? AND original_index=? LIMIT 1');
                $stmt->execute([$uid, $seq]);
            } else {
                $stmt = $pdo->prepare('SELECT id FROM books WHERE user_id=? AND TRIM(title)=TRIM(?) LIMIT 1');
                $stmt->execute([$uid, $title]);
            }

            $bookId = $stmt->fetchColumn();
            if (!$bookId) {
                $skipped++;
                continue;
            }

            $sid = $url ? subject_id_from_url($url) : '';

            $stmt = $pdo->prepare('
                UPDATE books SET
                    douban_subject_id = COALESCE(NULLIF(?, ""), douban_subject_id),
                    douban_url = COALESCE(NULLIF(?, ""), douban_url),
                    douban_rating = COALESCE(NULLIF(?, ""), douban_rating),
                    douban_read_count = COALESCE(NULLIF(?, ""), douban_read_count),
                    douban_rating_count = COALESCE(NULLIF(?, ""), douban_rating_count),
                    douban_match_status = "csv_imported",
                    douban_last_error = NULL,
                    douban_updated_at = NOW()
                WHERE id=? AND user_id=?
            ');

            $stmt->execute([
                $sid,
                $url,
                $rating,
                $readCount,
                $ratingCount,
                $bookId,
                $uid
            ]);

            $updated++;
        }

        fclose($fh);

        $msg = "豆瓣信息导入完成：更新 {$updated} 本，跳过 {$skipped} 行。";
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$title = '豆瓣信息 CSV 导入';
include __DIR__ . '/../app/layout/header.php';
?>

<div class="card">
    <p class="muted">这是豆瓣自动获取被限制时的稳定兜底方式。系统会按“序号”优先匹配书籍；没有序号时按书名匹配。</p>

    <?php if ($msg): ?>
        <div class="alert"><?= e($msg) ?></div>
    <?php endif; ?>

    <?php if ($err): ?>
        <div class="alert danger"><?= e($err) ?></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>上传 CSV</h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div>
                <label>CSV 文件</label>
                <input type="file" name="csv" accept=".csv,text/csv" required>
            </div>
        </div>
        <p>
            <button>导入豆瓣信息</button>
            <a class="btn ghost" href="import.php">返回导入导出</a>
            <a class="btn ghost" href="records.php">返回阅读历史</a>
        </p>
    </form>
</div>

<div class="card">
    <h2>CSV 标准字段</h2>
    <div class="table-wrap">
        <table>
            <tr>
                <th>字段</th>
                <th>说明</th>
            </tr>
            <tr><td>序号</td><td>优先用序号匹配书籍</td></tr>
            <tr><td>书名</td><td>没有序号时按书名匹配</td></tr>
            <tr><td>豆瓣URL</td><td>例如 https://book.douban.com/subject/xxxxxxx/</td></tr>
            <tr><td>豆瓣评分</td><td>可为空</td></tr>
            <tr><td>豆瓣阅读人数</td><td>可为空</td></tr>
            <tr><td>豆瓣评价人数</td><td>可为空</td></tr>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
