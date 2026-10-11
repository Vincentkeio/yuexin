<?php
if (!function_exists('current_path')) {
    function current_path(): string
    {
        return basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'dashboard.php');
    }
}

if (!function_exists('yx_active')) {
    function yx_active(array $pages): string
    {
        return in_array(current_path(), $pages, true) ? 'active' : '';
    }
}

$path = current_path();
$cssVersion = @filemtime(__DIR__ . '/../../public/assets/css/app.css') ?: '1';
$jsVersion = @filemtime(__DIR__ . '/../../public/assets/js/app.js') ?: '1';
$hideNav = in_array($path, ['login.php', 'register.php'], true);
$pageTitle = $title ?? '阅心';

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

$displayName = '用户';
if (!$hideNav) {
    try {
        $headerUser = current_user();
        if (!empty($headerUser['username'])) {
            $displayName = (string)$headerUser['username'];
        }
    } catch (Throwable $e) {
        // 页面本身会处理登录状态，页头保持可用。
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?> - 阅心</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#f7f4ec">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= e($cssVersion) ?>">
    <script defer src="assets/js/app.js?v=<?= e($jsVersion) ?>"></script>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=20261012">
</head>
<body class="yx-page-<?= e(pathinfo($path, PATHINFO_FILENAME)) ?>">

<header class="yx-app-header">
    <div class="yx-app-header-inner">
        <a class="yx-app-brand" href="dashboard.php" aria-label="返回阅心首页">
            <span class="yx-app-brand-mark" aria-hidden="true"></span>
            <span class="yx-app-brand-name">阅心</span>
        </a>

        <div class="yx-page-title"><?= e($pageTitle) ?></div>

        <?php if (!$hideNav): ?>
            <nav class="yx-desktop-nav" aria-label="主导航">
                <a class="<?= yx_active(['dashboard.php']) ?>" href="dashboard.php">首页</a>
                <a class="<?= yx_active(['records.php']) ?>" href="records.php">已读</a>
                <a class="<?= yx_active(['plans.php','plan_detail.php','plan_create.php','plan_edit.php']) ?>" href="plans.php">计划</a>
                <a class="<?= yx_active(['stats.php']) ?>" href="stats.php">统计</a>
                <a class="<?= yx_active(['account.php']) ?>" href="account.php">我的</a>
            </nav>

            <div class="yx-header-user">
                <span><?= e($displayName) ?></span>
                <a href="logout.php">退出</a>
            </div>
        <?php endif; ?>

        <?php if (!$hideNav): ?>
            <button class="yx-icon-button yx-mobile-more" type="button" id="yxOpenMoreMenu" aria-label="打开网站菜单" aria-expanded="false" aria-controls="yxMoreSheet">菜单</button>
        <?php endif; ?>
    </div>
</header>

<?php if (!$hideNav): ?>
    <nav class="yx-mobile-bottom-nav" aria-label="移动端主导航">
        <a class="<?= yx_active(['dashboard.php']) ?>" href="dashboard.php"><span aria-hidden="true">⌂</span><b>首页</b></a>
        <a class="<?= yx_active(['records.php']) ?>" href="records.php"><span aria-hidden="true">✓</span><b>已读</b></a>
        <a class="yx-mobile-add" href="record_create.php" aria-label="添加阅读记录"><span aria-hidden="true">＋</span></a>
        <a class="<?= yx_active(['plans.php','plan_detail.php','plan_create.php','plan_edit.php']) ?>" href="plans.php"><span aria-hidden="true">□</span><b>计划</b></a>
        <a class="<?= yx_active(['account.php']) ?>" href="account.php"><span aria-hidden="true">○</span><b>我的</b></a>
    </nav>

    <div class="yx-sheet-backdrop" id="yxMoreSheet" hidden>
        <section class="yx-bottom-sheet" role="dialog" aria-modal="true" aria-labelledby="yxMoreTitle">
            <div class="yx-sheet-handle" aria-hidden="true"></div>
            <div class="yx-sheet-head">
                <div>
                    <p>更多功能</p>
                    <h2 id="yxMoreTitle">工具与设置</h2>
                </div>
                <button class="yx-sheet-close" type="button" data-yx-close-sheet aria-label="关闭">×</button>
            </div>
            <div class="yx-sheet-menu">
                <a href="import.php"><span>导入与导出</span><small>CSV 与数据迁移</small></a>
                <a href="import_records.php"><span>导入记录</span><small>查看历史任务</small></a>
                <a href="douban_import.php"><span>豆瓣工具</span><small>导入与补全书籍信息</small></a>
                <a href="stats.php"><span>阅读统计</span><small>趋势与汇总</small></a>
                <a href="account.php"><span>账号设置</span><small>账户和安全</small></a>
                <a class="yx-sheet-danger" href="logout.php"><span>退出登录</span><small><?= e($displayName) ?></small></a>
            </div>
        </section>
    </div>
<?php endif; ?>

<main class="page">
<?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert">
        <?= e($_SESSION['flash']) ?>
        <?php unset($_SESSION['flash']); ?>
    </div>
<?php endif; ?>

<?php if (!$hideNav): ?>
<script>
(function () {
    const open = document.getElementById('yxOpenMoreMenu');
    const sheet = document.getElementById('yxMoreSheet');
    if (!open || !sheet) return;

    function show() {
        sheet.hidden = false;
        document.body.classList.add('yx-sheet-open');
        open.setAttribute('aria-expanded', 'true');
    }

    function hide() {
        sheet.hidden = true;
        document.body.classList.remove('yx-sheet-open');
        open.setAttribute('aria-expanded', 'false');
    }

    open.addEventListener('click', show);
    sheet.addEventListener('click', function (event) {
        if (event.target === sheet || event.target.closest('[data-yx-close-sheet]')) hide();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !sheet.hidden) hide();
    });
})();
</script>
<?php endif; ?>
