<?php
require_once __DIR__ . '/../app/bootstrap.php';

$u = require_login();
$uid = (int)$u['id'];
$pdo = db();

function yx_norm_title($title): string
{
    $title = trim((string)$title);
    $title = preg_replace('/\s+/u', '', $title);
    $title = str_replace(['《','》','〈','〉','“','”','"',"'",'：',':','（','）','(',')'], '', $title);
    return mb_strtolower($title, 'UTF-8');
}

function yx_empty($v): bool
{
    return $v === null || $v === '' || $v === 'unknown';
}

function yx_seq_value($v): int
{
    $v = trim((string)$v);
    return ($v !== '' && is_numeric($v)) ? (int)$v : 999999999;
}

function get_duplicate_book_groups(PDO $pdo, int $uid): array
{
    $stmt = $pdo->prepare('SELECT * FROM books WHERE user_id=? AND title IS NOT NULL AND TRIM(title)<>"" ORDER BY id ASC');
    $stmt->execute([$uid]);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($books as $b) {
        $key = yx_norm_title($b['title']);
        if ($key !== '') {
            $groups[$key][] = $b;
        }
    }

    return array_filter($groups, fn($g) => count($g) > 1);
}

function get_duplicate_history_groups(PDO $pdo, int $uid): array
{
    $stmt = $pdo->prepare('
        SELECT
            t.id AS task_id,
            t.book_id,
            t.raw_book_title,
            t.task_original_index,
            b.title,
            b.original_index
        FROM reading_tasks t
        LEFT JOIN books b ON b.id=t.book_id
        WHERE t.user_id=?
        ORDER BY t.id ASC
    ');
    $stmt->execute([$uid]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($tasks as $t) {
        $title = $t['title'] ?: $t['raw_book_title'];
        $key = yx_norm_title($title);
        if ($key !== '') {
            $groups[$key][] = $t;
        }
    }

    return array_filter($groups, fn($g) => count($g) > 1);
}

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $pdo->beginTransaction();

    try {
        $bookGroups = get_duplicate_book_groups($pdo, $uid);

        $mergedBookGroups = 0;
        $deletedBooks = 0;
        $mergedHistoryGroups = 0;
        $deletedTasks = 0;

        $bookFields = [
            'original_index','subtitle','original_title','author','translator','author_country','author_country_raw',
            'publisher','publish_date','publish_year','isbn','book_type','tags','media_text','format','platform',
            'is_full','is_paper','is_ebook','is_audiobook','cover_url','status','progress','first_start_date',
            'final_finish_date','rating_score','self_rating_note','rating_level','short_review','notes',
            'douban_subject_id','douban_url','douban_title','douban_author','douban_publisher','douban_publish_date',
            'douban_cover_url','douban_rating','douban_rating_count','douban_read_count','douban_wish_count',
            'douban_summary','douban_updated_at'
        ];

        foreach ($bookGroups as $items) {
            usort($items, function($a, $b) {
                return yx_seq_value($a['original_index'] ?? '') <=> yx_seq_value($b['original_index'] ?? '')
                    ?: ((int)$a['id'] <=> (int)$b['id']);
            });

            $keep = $items[0];
            $keepId = (int)$keep['id'];
            $merged = $keep;
            $dupIds = [];

            foreach (array_slice($items, 1) as $dup) {
                $dupIds[] = (int)$dup['id'];

                foreach ($bookFields as $f) {
                    if ($f === 'original_index') {
                        if (yx_seq_value($dup[$f] ?? '') < yx_seq_value($merged[$f] ?? '')) {
                            $merged[$f] = $dup[$f];
                        }
                        continue;
                    }

                    if (yx_empty($merged[$f] ?? null) && !yx_empty($dup[$f] ?? null)) {
                        $merged[$f] = $dup[$f];
                    }
                }
            }

            $set = [];
            $params = [];
            foreach ($bookFields as $f) {
                $set[] = "`$f`=?";
                $params[] = $merged[$f] ?? null;
            }
            $params[] = $keepId;
            $params[] = $uid;

            $pdo->prepare('UPDATE books SET '.implode(',', $set).' WHERE id=? AND user_id=?')->execute($params);

            if ($dupIds) {
                $in = implode(',', array_fill(0, count($dupIds), '?'));

                $params = array_merge([$keepId], $dupIds, [$uid]);
                $pdo->prepare("UPDATE reading_tasks SET book_id=? WHERE book_id IN ($in) AND user_id=?")->execute($params);

                $params = array_merge($dupIds, [$uid]);
                $stmt = $pdo->prepare("DELETE FROM books WHERE id IN ($in) AND user_id=?");
                $stmt->execute($params);
                $deletedBooks += $stmt->rowCount();
            }

            $mergedBookGroups++;
        }

        $historyGroups = get_duplicate_history_groups($pdo, $uid);

        foreach ($historyGroups as $items) {
            usort($items, function($a, $b) {
                $sa = $a['original_index'] ?: ($a['task_original_index'] ?? '');
                $sb = $b['original_index'] ?: ($b['task_original_index'] ?? '');
                return yx_seq_value($sa) <=> yx_seq_value($sb)
                    ?: ((int)$a['task_id'] <=> (int)$b['task_id']);
            });

            $dupTaskIds = array_map(fn($x) => (int)$x['task_id'], array_slice($items, 1));

            if ($dupTaskIds) {
                $in = implode(',', array_fill(0, count($dupTaskIds), '?'));
                $params = array_merge($dupTaskIds, [$uid]);
                $stmt = $pdo->prepare("DELETE FROM reading_tasks WHERE id IN ($in) AND user_id=?");
                $stmt->execute($params);
                $deletedTasks += $stmt->rowCount();
                $mergedHistoryGroups++;
            }
        }

        $pdo->prepare('
            UPDATE reading_tasks t
            JOIN books b ON b.id=t.book_id
            SET t.task_original_index=b.original_index
            WHERE t.user_id=? AND t.book_id IS NOT NULL
        ')->execute([$uid]);

        $pdo->commit();

        $result = [
            'merged_book_groups' => $mergedBookGroups,
            'deleted_books' => $deletedBooks,
            'merged_history_groups' => $mergedHistoryGroups,
            'deleted_tasks' => $deletedTasks,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        $result = ['error' => $e->getMessage()];
    }
}

$bookGroups = get_duplicate_book_groups($pdo, $uid);
$historyGroups = get_duplicate_history_groups($pdo, $uid);

$title = '合并去重';
include __DIR__ . '/../app/layout/header.php';
?>

<div class="card">
    <p class="muted">按书名合并重复书籍，并删除重复的阅读历史记录。适合多次导入后使用。</p>

    <?php if ($result): ?>
        <div class="alert">
            <?php if (!empty($result['error'])): ?>
                合并失败：<?= e($result['error']) ?>
            <?php else: ?>
                合并完成：
                书库重复组 <?= e($result['merged_book_groups']) ?>，
                删除重复书籍 <?= e($result['deleted_books']) ?>；
                阅读历史重复组 <?= e($result['merged_history_groups']) ?>，
                删除重复历史 <?= e($result['deleted_tasks']) ?>。
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p>书库重复组：<strong><?= count($bookGroups) ?></strong></p>
    <p>阅读历史重复组：<strong><?= count($historyGroups) ?></strong></p>

    <?php if ($bookGroups || $historyGroups): ?>
        <form method="post" onsubmit="return confirm('确定按书名合并去重？会删除重复书籍和重复阅读历史。');">
            <?= csrf_field() ?>
            <button class="danger">立即合并去重</button>
            <a class="btn ghost" href="records.php">返回阅读历史</a>
        </form>
    <?php else: ?>
        <p class="muted">没有检测到重复。</p>
        <a class="btn" href="records.php">返回阅读历史</a>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
