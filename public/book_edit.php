<?php
require_once __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/services/DoubanService.php';
$user = require_login();
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM books WHERE id=? AND user_id=?');
$stmt->execute([$id, $user['id']]);
$b = $stmt->fetch() ?: die('未找到');

function yx_post_null(string $key): ?string {
    $v = trim((string)($_POST[$key] ?? ''));
    return $v === '' ? null : $v;
}
function yx_progress(): int {
    return max(0, min(100, (int)($_POST['progress'] ?? 0)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $titleValue = yx_post_null('title');
    if (!$titleValue) {
        $_SESSION['flash'] = '书名不能为空';
        redirect('book_edit.php?id='.$id);
    }

    $doubanUrl = yx_post_null('douban_url');
    $subjectId = $doubanUrl ? DoubanService::extractSubjectId($doubanUrl) : null;

    $stmt = db()->prepare('UPDATE books SET
        title=?,subtitle=?,original_title=?,author=?,translator=?,author_country=?,author_country_raw=?,
        publisher=?,publish_date=?,publish_year=?,isbn=?,book_type=?,tags=?,media_text=?,format=?,platform=?,
        is_full=?,is_paper=?,is_ebook=?,is_audiobook=?,cover_url=?,status=?,progress=?,first_start_date=?,
        final_finish_date=?,rating_score=?,rating_level=?,short_review=?,notes=?,douban_url=?,
        douban_subject_id=COALESCE(?, douban_subject_id)
        WHERE id=? AND user_id=?');

    $stmt->execute([
        $titleValue, yx_post_null('subtitle'), yx_post_null('original_title'), yx_post_null('author'),
        yx_post_null('translator'), yx_post_null('author_country'), yx_post_null('author_country_raw'),
        yx_post_null('publisher'), yx_post_null('publish_date'), yx_post_null('publish_year'), yx_post_null('isbn'),
        yx_post_null('book_type'), yx_post_null('tags'), yx_post_null('media_text'), $_POST['format'] ?? 'unknown',
        yx_post_null('platform'), yx_post_null('is_full'), isset($_POST['is_paper']) ? 1 : 0,
        isset($_POST['is_ebook']) ? 1 : 0, isset($_POST['is_audiobook']) ? 1 : 0,
        yx_post_null('cover_url'), $_POST['status'] ?? 'unread', yx_progress(), yx_post_null('first_start_date'),
        yx_post_null('final_finish_date'), yx_post_null('rating_score'), yx_post_null('rating_level'),
        yx_post_null('short_review'), yx_post_null('notes'), $doubanUrl, $subjectId, $id, (int)$user['id']
    ]);

    try { DoubanService::autoBindBookById($id, (int)$user['id']); } catch (Throwable $e) {}
    $_SESSION['flash'] = '书籍已更新，已自动尝试连接豆瓣';
    redirect('book_detail.php?id='.$id);
}

$title = '编辑书籍';
include __DIR__.'/../app/layout/header.php';
require __DIR__.'/book_form.php';
?>
<div class="card"><form method="post"><?= csrf_field() ?><?php book_form($b); ?><p><button>保存修改</button> <a class="btn ghost" href="book_detail.php?id=<?= $id ?>">返回</a></p></form></div>
<?php include __DIR__.'/../app/layout/footer.php'; ?>
