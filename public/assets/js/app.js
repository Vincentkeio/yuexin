(function () {
  'use strict';

  function $(id) {
    return document.getElementById(id);
  }

  function friendly(message) {
    const raw = String(message || '').trim();
    const lower = raw.toLowerCase();

    if (!raw) return '';

    // 书籍/豆瓣侧失败，不要说成系统入口异常。
    if (
      raw.includes('404 Not Found') ||
      raw.includes('Not Found') ||
      raw.includes('豆瓣') ||
      raw.includes('subject') ||
      raw.includes('sec.douban') ||
      raw.includes('请 登录 使用豆瓣') ||
      raw.includes('异常请求') ||
      raw.includes('403') ||
      lower.includes('forbidden') ||
      lower.includes('rate limit')
    ) {
      return '部分书籍信息暂时获取失败，任务会继续处理其他书籍。你可以稍后重试。';
    }

    if (raw.includes('Operation timed out') || lower.includes('timed out') || lower.includes('timeout')) {
      return '获取信息超时了，可能是网络较慢。任务会继续处理其他书籍。';
    }

    if (raw.includes('open_basedir') || raw.includes('allowed path')) {
      return '服务器权限限制导致任务启动失败，请稍后重试。';
    }

    if (raw.includes('Fatal error') || raw.includes('Parse error') || raw.includes('Warning')) {
      return '系统处理时遇到异常，请稍后重试。';
    }

    if (raw.includes('Failed to fetch') || raw.includes('NetworkError')) {
      return '网络请求失败，请检查网络后重试。';
    }

    if (raw.includes('PHP CLI') || raw.includes('shell_exec') || raw.includes('Worker') || raw.includes('worker')) {
      return '任务已提交，后台正在处理。请稍后刷新查看进度。';
    }

    if (raw.includes('没有 pending') || raw.includes('没有可继续')) {
      return '当前没有需要继续处理的书籍。';
    }

    return raw;
  }




  function doubanApiCandidates() {
    const list = [];

    function add(url) {
      if (!url) return;
      if (!list.includes(url)) list.push(url);
    }

    if (window.YX_DOUBAN_API) add(window.YX_DOUBAN_API);

    // 当前页面同目录，兼容 /books.php 和 /public/books.php
    add(new URL('douban_job_api.php', window.location.href).pathname);

    // 常见部署方式
    add('/douban_job_api.php');
    add('/public/douban_job_api.php');

    // 根据 app.js 路径反推
    const script = document.currentScript || document.querySelector('script[src*="assets/js/app.js"]');
    if (script) {
      try {
        const src = script.getAttribute('src') || '';
        const u = new URL(src, window.location.href);
        add(new URL('../../douban_job_api.php', u).pathname);
        add(new URL('../../../douban_job_api.php', u).pathname);
      } catch (e) {}
    }

    return list;
  }


  function doubanApiUrl() {
    return '/douban_job_api.php';
  }






  async function postDouban(action, extra) {
    const candidates = window.YX_DOUBAN_API_OK
      ? [window.YX_DOUBAN_API_OK]
      : doubanApiCandidates();

    let lastSystemError = '';
    let lastBusinessError = '';

    for (const apiUrl of candidates) {
      const body = new URLSearchParams();
      body.set('action', action);

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
      if (csrfToken) body.set('csrf_token', csrfToken);

      if (extra && typeof extra === 'object') {
        Object.keys(extra).forEach(function (key) {
          if (extra[key] !== undefined && extra[key] !== null) {
            body.set(key, String(extra[key]));
          }
        });
      }

      let res;
      let raw = '';

      try {
        res = await fetch(apiUrl, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: body.toString(),
          credentials: 'same-origin',
          cache: 'no-store',
          redirect: 'follow'
        });

        raw = await res.text();
      } catch (e) {
        lastSystemError = e.message || String(e);
        continue;
      }

      const trimmed = raw.trim();
      const lower = trimmed.toLowerCase();
      const finalUrl = res.url || '';
      const looksLikeHtml = lower.includes('<!doctype') || lower.includes('<html') || lower.includes('<body') || lower.includes('<form');

      if (finalUrl.includes('login.php') || (looksLikeHtml && (raw.includes('name="username"') || raw.includes('type="password"')))) {
        throw new Error('登录状态已过期，请重新登录后再操作。');
      }

      // 真正的接口路径 404：继续试下一个路径。
      if (
        res.status === 404 ||
        /^(404\s+Not\s+Found|Not\s+Found)$/i.test(trimmed) ||
        lower.includes('<title>404 not found</title>')
      ) {
        lastSystemError = apiUrl + ' 返回 404';
        continue;
      }

      let data;
      try {
        data = JSON.parse(raw);
      } catch (e) {
        // HTML 错误页不是书籍失败，是入口/程序异常，继续试其他路径。
        if (looksLikeHtml) {
          lastSystemError = apiUrl + ' 返回网页而不是数据';
          continue;
        }

        lastBusinessError = raw.slice(0, 300);
        continue;
      }

      // 只要能解析 JSON，就说明接口入口是好的。
      window.YX_DOUBAN_API_OK = apiUrl;

      if (!res.ok || data.ok === false) {
        const msg = data.message || data.last_error || '请求失败，请稍后重试。';

        // 业务失败走普通提示，不再说系统入口异常。
        throw new Error(friendly(msg));
      }

      return data;
    }

    console.error('[Yuexin API failed]', {
      candidates: candidates,
      lastSystemError: lastSystemError,
      lastBusinessError: lastBusinessError
    });

    if (lastBusinessError) {
      throw new Error(friendly(lastBusinessError));
    }

    throw new Error('任务暂时无法启动，请刷新页面后重试。');
  }





  function ensureUiRoot() {
    let root = $('yx-ui-root');
    if (root) return root;

    root = document.createElement('div');
    root.id = 'yx-ui-root';
    root.innerHTML = `
      <div class="yx-toast-wrap" id="yxToastWrap"></div>

      <div class="yx-confirm-mask" id="yxConfirmMask" hidden>
        <div class="yx-confirm-card" role="dialog" aria-modal="true">
          <div class="yx-confirm-icon">?</div>
          <div class="yx-confirm-body">
            <h3 id="yxConfirmTitle">确认操作</h3>
            <p id="yxConfirmMessage"></p>
          </div>
          <div class="yx-confirm-actions">
            <button type="button" class="btn ghost" id="yxConfirmCancel">取消</button>
            <button type="button" class="btn" id="yxConfirmOk">确认</button>
          </div>
        </div>
      </div>

      <div class="yx-modal-mask" id="yxModalMask" hidden>
        <div class="yx-modal-card" role="dialog" aria-modal="true">
          <div class="yx-modal-icon" id="yxModalIcon">i</div>
          <div class="yx-modal-body">
            <h3 id="yxModalTitle">提示</h3>
            <p id="yxModalMessage"></p>
          </div>
          <div class="yx-modal-actions">
            <button type="button" class="yx-modal-ok" id="yxModalOk">知道了</button>
          </div>
        </div>
      </div>

      <div class="yx-douban-modal-mask" id="globalDoubanModal" hidden>
        <div class="yx-douban-modal-card">
          <div class="yx-douban-modal-head">
            <div>
              <p class="yx-douban-modal-kicker">全站后台任务 / 豆瓣信息</p>
              <h2>豆瓣信息获取</h2>
              <p class="muted">任务在服务器后台处理。关闭弹窗或切换选项卡后，仍可通过右下角悬浮条查看。</p>
            </div>
            <button type="button" class="yx-douban-modal-close" id="globalDoubanClose">×</button>
          </div>

          <div class="sync-progress-wrap">
            <div class="sync-progress-bar" id="globalDoubanBar"></div>
          </div>

          <div class="sync-numbers">
            <span>总数：<strong id="globalDoubanTotal">0</strong></span>
            <span>已处理：<strong id="globalDoubanDone">0</strong></span>
            <span>成功：<strong id="globalDoubanSuccess">0</strong></span>
            <span>失败：<strong id="globalDoubanFailed">0</strong></span>
          </div>

          <div id="globalDoubanNotice" class="alert" style="display:none;"></div>
          <p id="globalDoubanText" class="muted">等待开始……</p>
          <p id="globalDoubanError" class="muted"></p>

          <div class="actions">
            <button type="button" class="btn ghost" id="globalDoubanBackground">后台处理</button>
            <button type="button" class="btn" id="globalDoubanResume" style="display:none;">继续任务</button>
            <button type="button" class="btn ghost" id="globalDoubanRestart">重新开始任务</button>
            <button type="button" class="danger" id="globalDoubanCancel">中止获取</button>
            <button type="button" class="btn ghost" id="globalDoubanRefresh" style="display:none;">刷新当前页</button>
            <a class="btn ghost" href="douban_import.php">豆瓣信息 CSV 导入</a>
          </div>
        </div>
      </div>

      <button type="button" class="yx-douban-floating" id="globalDoubanFloating" hidden>
        <span class="yx-douban-floating-dot"></span>
        <span id="globalDoubanFloatingText">豆瓣任务</span>
      </button>
    `;

    document.body.appendChild(root);
    return root;
  }

  function yxToast(message, type = 'info', timeout = 3600) {
    ensureUiRoot();

    const wrap = $('yxToastWrap');
    if (!wrap) return;

    const item = document.createElement('div');
    item.className = 'yx-toast yx-toast-' + type;

    const icon = type === 'success' ? '✓' : type === 'danger' ? '!' : type === 'warning' ? '!' : 'i';
    item.innerHTML = `<span class="yx-toast-icon">${icon}</span><span class="yx-toast-text"></span>`;
    item.querySelector('.yx-toast-text').textContent = friendly(message);

    wrap.appendChild(item);
    requestAnimationFrame(() => item.classList.add('show'));

    setTimeout(() => {
      item.classList.remove('show');
      setTimeout(() => item.remove(), 240);
    }, timeout);
  }

  function yxAlert(message, title = '提示', type = 'warning') {
    ensureUiRoot();

    const mask = $('yxModalMask');
    const titleEl = $('yxModalTitle');
    const msgEl = $('yxModalMessage');
    const iconEl = $('yxModalIcon');
    const ok = $('yxModalOk');

    if (!mask || !titleEl || !msgEl || !iconEl || !ok) {
      alert(friendly(message));
      return;
    }

    titleEl.textContent = title;
    msgEl.textContent = friendly(message);
    iconEl.textContent = type === 'success' ? '✓' : type === 'danger' ? '!' : type === 'warning' ? '!' : 'i';
    iconEl.className = 'yx-modal-icon yx-modal-icon-' + type;

    mask.hidden = false;
    mask.style.display = 'flex';
    requestAnimationFrame(() => mask.classList.add('show'));

    const close = () => {
      mask.classList.remove('show');
      setTimeout(() => {
        mask.hidden = true;
        mask.style.display = 'none';
      }, 180);
      ok.removeEventListener('click', close);
    };

    ok.addEventListener('click', close);
    setTimeout(() => ok.focus(), 60);
  }

  function yxConfirm(message, title = '确认操作') {
    ensureUiRoot();

    return new Promise((resolve) => {
      const mask = $('yxConfirmMask');
      const titleEl = $('yxConfirmTitle');
      const msgEl = $('yxConfirmMessage');
      const ok = $('yxConfirmOk');
      const cancel = $('yxConfirmCancel');

      if (!mask || !titleEl || !msgEl || !ok || !cancel) {
        resolve(confirm(message));
        return;
      }

      titleEl.textContent = title;
      msgEl.textContent = message;

      mask.hidden = false;
      mask.style.display = 'flex';
      requestAnimationFrame(() => mask.classList.add('show'));

      const close = (value) => {
        mask.classList.remove('show');
        setTimeout(() => {
          mask.hidden = true;
          mask.style.display = 'none';
        }, 180);

        ok.removeEventListener('click', onOk);
        cancel.removeEventListener('click', onCancel);
        mask.removeEventListener('click', onMask);
        document.removeEventListener('keydown', onKey);

        resolve(value);
      };

      const onOk = () => close(true);
      const onCancel = () => close(false);
      const onMask = (e) => {
        if (e.target === mask) close(false);
      };
      const onKey = (e) => {
        if (e.key === 'Escape') close(false);
        if (e.key === 'Enter') close(true);
      };

      ok.addEventListener('click', onOk);
      cancel.addEventListener('click', onCancel);
      mask.addEventListener('click', onMask);
      document.addEventListener('keydown', onKey);

      setTimeout(() => ok.focus(), 60);
    });
  }

  window.yxToast = yxToast;
  window.yxAlert = yxAlert;
  window.yxConfirm = yxConfirm;

  let doubanTimer = null;
  let doubanLast = null;

  function showDoubanModal() {
    ensureUiRoot();

    const modal = $('globalDoubanModal');
    if (!modal) return;

    modal.hidden = false;
    modal.style.display = 'flex';
    requestAnimationFrame(() => modal.classList.add('show'));
  }

  function hideDoubanModal() {
    const modal = $('globalDoubanModal');
    if (!modal) return;

    modal.classList.remove('show');
    setTimeout(() => {
      modal.hidden = true;
      modal.style.display = 'none';
    }, 180);
  }

  function showFloating(data) {
    const btn = $('globalDoubanFloating');
    const text = $('globalDoubanFloatingText');

    if (!btn || !text) return;

    btn.hidden = false;
    btn.classList.add('show');

    if (data && data.running) {
      text.textContent = `豆瓣处理中 ${data.done || 0}/${data.total || 0}`;
    } else if (data && data.paused) {
      text.textContent = '豆瓣任务已暂停';
    } else if (data && data.exists) {
      text.textContent = '查看豆瓣处理结果';
    } else {
      text.textContent = '豆瓣任务';
    }
  }

  function hideFloating() {
    const btn = $('globalDoubanFloating');
    if (!btn) return;

    btn.classList.remove('show');
    setTimeout(() => {
      btn.hidden = true;
    }, 180);
  }

  function renderJob(data) {
    data = data || {};
    doubanLast = data;

    const total = Number(data.total || 0);
    const done = Number(data.done || 0);
    const pct = total > 0 ? Math.round(done / total * 100) : 0;

    if ($('globalDoubanBar')) $('globalDoubanBar').style.width = pct + '%';
    if ($('globalDoubanTotal')) $('globalDoubanTotal').textContent = total;
    if ($('globalDoubanDone')) $('globalDoubanDone').textContent = done;
    if ($('globalDoubanSuccess')) $('globalDoubanSuccess').textContent = data.success || 0;
    if ($('globalDoubanFailed')) $('globalDoubanFailed').textContent = data.failed || 0;

    const notice = $('globalDoubanNotice');
    const text = $('globalDoubanText');
    const err = $('globalDoubanError');
    const refresh = $('globalDoubanRefresh');
    const cancel = $('globalDoubanCancel');
    const bg = $('globalDoubanBackground');
    const resume = $('globalDoubanResume');
    const restart = $('globalDoubanRestart');

    if (notice) {
      if (data.message) {
        notice.style.display = 'block';
        notice.textContent = friendly(data.message);
        notice.classList.toggle('danger', !!(data.blocked || data.rate_limited || data.status === 'failed'));
      } else {
        notice.style.display = 'none';
      }
    }

    if (text) {
      text.textContent = data.current_title
        ? '正在处理：《' + data.current_title + '》'
        : (friendly(data.message) || '等待开始……');
    }

    if (err) {
      err.textContent = data.last_error ? '最近错误：' + friendly(data.last_error) : '';
    }

    const running = !!data.running;
    const paused = !!data.paused;
    const finished = !!(data.exists && data.finished);

    if (refresh) refresh.style.display = finished ? 'inline-flex' : 'none';
    if (resume) resume.style.display = paused ? 'inline-flex' : 'none';
    if (restart) restart.style.display = 'inline-flex';

    if (cancel) {
      cancel.disabled = finished;
      cancel.textContent = finished ? (data.cancelled ? '已中止' : '已结束') : '中止获取';
    }

    if (bg) {
      bg.disabled = finished;
      bg.textContent = finished ? '已结束' : '后台处理';
    }

    if (running || paused || finished) {
      showFloating(data);
    } else {
      hideFloating();
    }
  }

  async function checkJob() {
    try {
      const data = await postDouban('status');
      renderJob(data);

      if (data.exists && data.running) {
        if (doubanTimer) clearTimeout(doubanTimer);
        doubanTimer = setTimeout(checkJob, 5000);
        return;
      }

      if (data.exists && (data.paused || data.finished) && data.notify) {
        let title = '豆瓣任务状态已更新';
        let type = 'success';

        if (data.paused) {
          title = '豆瓣获取已暂停';
          type = 'warning';
        } else if (data.cancelled) {
          title = '豆瓣获取已中止';
          type = 'warning';
        }

        yxAlert(data.message || '豆瓣任务状态已更新。', title, type);

        try {
          await postDouban('mark_notified');
        } catch (e) {}
      }
    } catch (e) {
      console.error('[Yuexin Douban]', e);
    }
  }

  async function startJob() {
    showDoubanModal();

    try {
      const data = await postDouban('start');
      renderJob(data);

      if (doubanTimer) clearTimeout(doubanTimer);
      doubanTimer = setTimeout(checkJob, 1000);
    } catch (e) {
      yxAlert(e.message, '启动失败', 'danger');
    }
  }

  async function resumeJob() {
    showDoubanModal();

    try {
      const data = await postDouban('resume');
      renderJob(data);
      yxToast('已请求继续豆瓣后台任务。', 'success');

      if (doubanTimer) clearTimeout(doubanTimer);
      doubanTimer = setTimeout(checkJob, 1000);
    } catch (e) {
      yxAlert(e.message, '继续失败', 'danger');
    }
  }

  async function restartJob() {
    const ok = await yxConfirm(
      '确定重新开始任务？系统会重新检查需要补全的信息。',
      '重新开始任务'
    );

    if (!ok) return;

    showDoubanModal();

    renderJob({
      exists: true,
      running: true,
      message: '正在重新开始，请稍候……',
      total: 0,
      done: 0,
      success: 0,
      failed: 0
    });

    try {
      const data = await postDouban('restart', {restart: 1});
      renderJob(data);
      yxToast('任务已重新开始。', 'success');

      if (doubanTimer) clearTimeout(doubanTimer);
      doubanTimer = setTimeout(checkJob, 1000);
    } catch (e) {
      yxAlert(e.message, '启动失败', 'danger');
    }
  }




  async function cancelJob() {
    const ok = await yxConfirm('确定中止本次豆瓣后台任务？', '中止任务');

    if (!ok) return;

    try {
      const data = await postDouban('cancel');
      renderJob(data);
      yxToast('已中止豆瓣后台任务。', 'warning');
    } catch (e) {
      yxAlert(e.message, '中止失败', 'danger');
    }
  }

  window.yxOpenDoubanJob = function () {
    showDoubanModal();

    if (doubanLast) renderJob(doubanLast);

    checkJob();
  };

  document.addEventListener('DOMContentLoaded', function () {
    ensureUiRoot();

    document.addEventListener('click', function (e) {
      const start = e.target.closest('#openDoubanSyncModal, #yxGlobalDoubanOpen');
      const floating = e.target.closest('#globalDoubanFloating');
      const close = e.target.closest('#globalDoubanClose, #globalDoubanBackground');
      const resume = e.target.closest('#globalDoubanResume');
      const restart = e.target.closest('#globalDoubanRestart');
      const refresh = e.target.closest('#globalDoubanRefresh');
      const cancel = e.target.closest('#globalDoubanCancel');

      if (start) {
        e.preventDefault();
        startJob();
        return;
      }

      if (floating) {
        e.preventDefault();
        showDoubanModal();
        checkJob();
        return;
      }

      if (close) {
        e.preventDefault();
        hideDoubanModal();

        if (doubanLast && doubanLast.exists) showFloating(doubanLast);

        return;
      }

      if (resume) {
        e.preventDefault();
        resumeJob();
        return;
      }

      if (restart) {
        e.preventDefault();
        restartJob();
        return;
      }

      if (refresh) {
        e.preventDefault();
        window.location.reload();
        return;
      }

      if (cancel) {
        e.preventDefault();
        cancelJob();
      }
    }, true);

    checkJob();
  });


  document.addEventListener('submit', function (event) {
    const form = event.target.closest?.('form[data-confirm-form]');
    if (!form || form.dataset.confirmed === '1') return;

    const message = form.dataset.confirmForm || '确定继续？';
    event.preventDefault();

    if (typeof window.yxConfirm === 'function') {
      window.yxConfirm(message).then(function (ok) {
        if (!ok) return;
        form.dataset.confirmed = '1';
        form.requestSubmit();
      });
      return;
    }

    if (window.confirm(message)) {
      form.dataset.confirmed = '1';
      form.requestSubmit();
    }
  });

})();

/* hotfix: ensure add-book link always navigates normally */
document.addEventListener('click', function (e) {
  var a = e.target && e.target.closest
    ? e.target.closest('a.yx-add-book-link, a[href="/book_create.php"], a[href="book_create.php"]')
    : null;

  if (!a) return;

  e.stopImmediatePropagation();
  window.location.href = a.getAttribute('href') || '/book_create.php';
}, true);
