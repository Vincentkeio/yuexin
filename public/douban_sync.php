<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

unset($_SESSION['douban_sync']);

$title = '获取豆瓣信息';
include __DIR__ . '/../app/layout/header.php';
?>

<div class="card douban-sync-card">
    <p class="muted">
        系统会自动为缺少豆瓣信息的书籍获取豆瓣评分、豆瓣阅读人数和豆瓣链接。
        如果豆瓣限制当前服务器 IP，系统会自动中止，并弹出提示。
    </p>

    <div class="sync-progress-wrap">
        <div class="sync-progress-bar" id="syncBar"></div>
    </div>

    <div class="sync-numbers">
        <span>总数：<strong id="total">0</strong></span>
        <span>已处理：<strong id="done">0</strong></span>
        <span>成功：<strong id="success">0</strong></span>
        <span>失败：<strong id="failed">0</strong></span>
        <span>跳过：<strong id="skipped">0</strong></span>
        <span>连续失败：<strong id="consecutiveFailed">0</strong></span>
    </div>

    <div id="syncNotice" class="alert" style="display:none;"></div>

    <p id="syncText" class="muted">准备开始……</p>
    <p id="syncError" class="muted"></p>

    <div class="actions">
        <button type="button" class="danger" id="stopBtn">中止获取</button>
        <a class="btn ghost" href="records.php">返回阅读历史</a>
        <a class="btn ghost" href="douban_import.php">豆瓣信息 CSV 导入</a>
    </div>
</div>

<script>
(function () {
    const bar = document.getElementById('syncBar');
    const totalEl = document.getElementById('total');
    const doneEl = document.getElementById('done');
    const successEl = document.getElementById('success');
    const failedEl = document.getElementById('failed');
    const skippedEl = document.getElementById('skipped');
    const consecutiveFailedEl = document.getElementById('consecutiveFailed');
    const textEl = document.getElementById('syncText');
    const errEl = document.getElementById('syncError');
    const noticeEl = document.getElementById('syncNotice');
    const stopBtn = document.getElementById('stopBtn');

    let stopped = false;
    let blockedAlertShown = false;

    function showNotice(msg, danger) {
        if (!msg) {
            noticeEl.style.display = 'none';
            noticeEl.textContent = '';
            return;
        }

        noticeEl.style.display = 'block';
        noticeEl.textContent = msg;

        if (danger) {
            noticeEl.classList.add('danger');
        } else {
            noticeEl.classList.remove('danger');
        }
    }

    function render(data) {
        const total = data.total || 0;
        const done = data.done || 0;
        const pct = total > 0 ? Math.round(done / total * 100) : 0;

        totalEl.textContent = total;
        doneEl.textContent = done;
        successEl.textContent = data.success || 0;
        failedEl.textContent = data.failed || 0;
        skippedEl.textContent = data.skipped || 0;
        if (consecutiveFailedEl) consecutiveFailedEl.textContent = data.consecutive_failed || 0;
        bar.style.width = pct + '%';

        if (data.last_error) {
            errEl.textContent = '最近错误：' + data.last_error;
        }

        if (data.message) {
            showNotice(data.message, !!data.blocked);
        }
    }

    function nextDelay() {
        // 每本书之间随机等待 8-15 秒
        return 8000 + Math.floor(Math.random() * 7000);
    }

    async function call(action) {
        const res = await fetch('douban_sync_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: action,
                csrf_token: document.querySelector('meta[name="csrf-token"]')?.content || ''
            }).toString()
        });
        return await res.json();
    }

    function handleBlocked(data) {
        stopped = true;
        const msg = data.message || data.last_error || '豆瓣限制了当前服务器 IP，已自动中止本次获取任务。请稍后再试，或使用豆瓣信息 CSV 导入。';

        textEl.textContent = '豆瓣请求被限制，本次任务已自动中止。';
        showNotice(msg, true);

        stopBtn.disabled = true;
        stopBtn.textContent = '已暂停';

        if (!blockedAlertShown) {
            blockedAlertShown = true;
            if (window.yxAlert) { yxAlert(msg, '豆瓣请求已暂停', 'danger'); } else { alert(msg); }
        }
    }


    function handleRateLimited(data) {
        stopped = true;
        const msg = data.message || data.last_error || '连续失败次数过多，系统已自动暂停。建议稍后再继续。';

        textEl.textContent = '连续失败次数过多，本次任务已自动暂停。';
        showNotice(msg, true);

        stopBtn.disabled = true;
        stopBtn.textContent = '已暂停';

        if (!blockedAlertShown) {
            blockedAlertShown = true;
            if (window.yxAlert) { yxAlert(msg, '豆瓣请求已暂停', 'danger'); } else { alert(msg); }
        }
    }

    async function start() {
        try {
            const data = await call('start');
            render(data);

            if (data.blocked) {
                handleBlocked(data);
                return;
            }

            if (data.rate_limited) {
                handleRateLimited(data);
                return;
            }

            if (data.rate_limited) {
                handleRateLimited(data);
                return;
            }

            if (data.finished) {
                textEl.textContent = data.message || '暂无需要获取豆瓣信息的书籍。';
                stopBtn.disabled = true;
                return;
            }

            textEl.textContent = '开始处理……';
            setTimeout(step, nextDelay());
        } catch (e) {
            textEl.textContent = '初始化失败，请刷新重试。';
        }
    }

    async function step() {
        if (stopped) {
            return;
        }

        try {
            const data = await call('step');
            render(data);

            if (data.blocked) {
                handleBlocked(data);
                return;
            }

            if (data.cancelled) {
                stopped = true;
                textEl.textContent = '已中止。已处理 ' + (data.done || 0) + ' 本。';
                stopBtn.disabled = true;
                stopBtn.textContent = '已中止';
                return;
            }

            if (data.finished) {
                textEl.textContent = '完成。成功 ' + (data.success || 0) + ' 本，失败 ' + (data.failed || 0) + ' 本。';
                stopBtn.disabled = true;
                stopBtn.textContent = '已完成';
                return;
            }

            textEl.textContent = data.current_title ? '正在处理：《' + data.current_title + '》' : '正在处理……';
            setTimeout(step, nextDelay());
        } catch (e) {
            textEl.textContent = '请求失败，请稍后刷新重试。';
        }
    }

    stopBtn.addEventListener('click', async function () {
        if (stopped) return;

        if (!confirm('确定中止本次豆瓣信息获取？')) {
            return;
        }

        stopped = true;
        stopBtn.disabled = true;
        stopBtn.textContent = '正在中止……';

        try {
            const data = await call('cancel');
            render(data);
            textEl.textContent = '已中止。已处理 ' + (data.done || 0) + ' 本。';
            stopBtn.textContent = '已中止';
        } catch (e) {
            textEl.textContent = '中止请求失败，但页面已停止继续处理。';
            stopBtn.textContent = '已停止';
        }
    });

    start();
})();
</script>

<?php include __DIR__ . '/../app/layout/footer.php'; ?>
