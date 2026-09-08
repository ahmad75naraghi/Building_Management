/* ============================================================
 * مدیریت ساختمان پرو — ارتقای تجربهٔ کاربری (ui-enhance.js)
 *
 *   ۱. توست (اسنک‌بار) پایین صفحه با محو خودکار ۳ ثانیه:
 *      - پیام‌های سرور (.app-alert) به‌صورت خودکار به توست تبدیل می‌شوند
 *      - فراخوانی برنامه‌ای: window.showToast('متن', 'success'|'error')
 *   ۲. نوار پیشرفت طلایی بالای صفحه هنگام ناوبری/ارسال فرم
 *      + حالت «در حال ارسال» روی دکمهٔ اصلی فرم‌ها
 *   ۳. جستجو + صفحه‌بندی فهرست‌های بلند (۲۰ آیتم در هر صفحه):
 *      - کانتینر آیتم‌ها: <div data-list-items="شناسه">
 *      - ورودی جستجو:    <input data-list-search="شناسه">
 *      - جای صفحه‌بندی:   <div data-list-pager="شناسه"></div>
 * ============================================================ */
(function () {
    'use strict';

    var PAGE_SIZE = 20;
    var TOAST_MS = 3000;

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    /* ----------------------------------------------------------
     * ۱) سیستم توست
     * ---------------------------------------------------------- */
    function toastContainer() {
        var c = document.getElementById('toast-stack');
        if (!c) {
            c = document.createElement('div');
            c.id = 'toast-stack';
            document.body.appendChild(c);
        }
        return c;
    }

    function showToast(message, type, timeoutMs) {
        if (!message) { return; }
        type = type === 'error' ? 'error' : 'success';
        var t = document.createElement('div');
        t.className = 'app-toast app-toast-' + type;
        t.setAttribute('role', 'status');

        var icon = document.createElement('span');
        icon.className = 'app-toast-icon';
        icon.innerHTML = type === 'success'
            ? '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>'
            : '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';

        var text = document.createElement('span');
        text.textContent = message;

        t.appendChild(icon);
        t.appendChild(text);
        toastContainer().appendChild(t);

        var dismiss = function () {
            t.classList.add('app-toast-out');
            window.setTimeout(function () { t.remove(); }, 260);
        };
        t.addEventListener('click', dismiss);
        window.setTimeout(dismiss, timeoutMs || TOAST_MS);
    }

    /** تبدیل بنر پیام سرور به توست (بدون جاوااسکریپت، بنر باقی می‌ماند) */
    function convertServerAlerts() {
        var alerts = document.querySelectorAll('.app-alert');
        for (var i = 0; i < alerts.length; i++) {
            var el = alerts[i];
            var span = el.querySelector('span');
            var msg = span ? span.textContent.trim() : el.textContent.trim();
            var type = el.classList.contains('app-alert-success') ? 'success' : 'error';
            showToast(msg, type);
            el.remove();
        }
    }

    /* ----------------------------------------------------------
     * ۲) نوار پیشرفت + حالت ارسال فرم
     * ---------------------------------------------------------- */
    var bar = null;
    var barTimer = null;

    function progressStart() {
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'page-progress';
            document.body.appendChild(bar);
        }
        bar.classList.remove('done');
        bar.classList.add('active');
        if (barTimer) { clearTimeout(barTimer); }
    }

    function progressDone() {
        if (!bar) { return; }
        bar.classList.add('done');
        barTimer = window.setTimeout(function () {
            bar.classList.remove('active', 'done');
        }, 420);
    }

    function hookNavigationProgress() {
        // کلیک روی لینک‌های داخلی → شروع نوار پیشرفت
        document.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!a) { return; }
            var href = a.getAttribute('href') || '';
            if (a.target === '_blank' || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
            if (href.indexOf('#') === 0 || href.indexOf('javascript:') === 0) { return; }
            if (a.hasAttribute('data-modal-open') || a.hasAttribute('data-modal-close')) { return; }
            progressStart();
        }, true);

        // ارسال فرم → نوار پیشرفت + اسپینر روی دکمهٔ ارسال
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') { return; }
            progressStart();
            var btn = form.querySelector('[type="submit"]');
            if (btn && !btn.classList.contains('is-submitting')) {
                btn.classList.add('is-submitting');
                var original = btn.innerHTML;
                btn.dataset.originalHtml = original;
                btn.disabled = true;
                btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span>در حال ارسال…</span>';
                // اگر ارسال لغو/متوقف شد (مثلاً اعتبارسنجی مرورگر بعداً رد کرد) برگردان
                window.setTimeout(function () {
                    if (btn.disabled && btn.classList.contains('is-submitting')) {
                        btn.classList.remove('is-submitting');
                        btn.disabled = false;
                        btn.innerHTML = btn.dataset.originalHtml || '';
                    }
                }, 20000);
            }
        }, true);

        window.addEventListener('pageshow', progressDone);
    }

    /* ----------------------------------------------------------
     * ۳) جستجو + صفحه‌بندی فهرست‌ها
     * ---------------------------------------------------------- */
    function faNum(n) {
        return String(n).replace(/\d/g, function (d) {
            return '۰۱۲۳۴۵۶۷۸۹'[+d];
        });
    }

    function normalize(s) {
        return (s || '')
            .toLowerCase()
            .replace(/ي/g, 'ی')
            .replace(/ك/g, 'ک')
            .replace(/[‌\u200c]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function setupListWidget(id) {
        var itemsEl = document.querySelector('[data-list-items="' + id + '"]');
        if (!itemsEl) { return; }
        var searchEl = document.querySelector('[data-list-search="' + id + '"]');
        var pagerEl = document.querySelector('[data-list-pager="' + id + '"]');
        var countEl = document.querySelector('[data-list-count="' + id + '"]');

        var items = Array.prototype.slice.call(itemsEl.children);
        var texts = items.map(function (el) { return normalize(el.textContent); });
        var state = { q: '', page: 1 };

        function filteredIdx() {
            var out = [];
            for (var i = 0; i < items.length; i++) {
                if (!state.q || texts[i].indexOf(state.q) !== -1) { out.push(i); }
            }
            return out;
        }

        function render() {
            var idx = filteredIdx();
            var pages = Math.max(1, Math.ceil(idx.length / PAGE_SIZE));
            if (state.page > pages) { state.page = pages; }
            var start = (state.page - 1) * PAGE_SIZE;
            var visible = {};
            for (var j = start; j < Math.min(start + PAGE_SIZE, idx.length); j++) {
                visible[idx[j]] = true;
            }
            for (var k = 0; k < items.length; k++) {
                items[k].style.display = visible[k] ? '' : 'none';
            }

            if (countEl) {
                countEl.textContent = idx.length === items.length
                    ? faNum(idx.length) + ' مورد'
                    : faNum(idx.length) + ' از ' + faNum(items.length) + ' مورد';
            }

            if (!pagerEl) { return; }
            if (idx.length <= PAGE_SIZE) { pagerEl.innerHTML = ''; return; }
            pagerEl.innerHTML =
                '<div class="list-pager">' +
                '  <button type="button" class="list-pager-btn" data-pager-dir="-1"' + (state.page <= 1 ? ' disabled' : '') + '>قبلی</button>' +
                '  <span class="list-pager-info">صفحهٔ ' + faNum(state.page) + ' از ' + faNum(pages) + '</span>' +
                '  <button type="button" class="list-pager-btn" data-pager-dir="1"' + (state.page >= pages ? ' disabled' : '') + '>بعدی</button>' +
                '</div>';
        }

        if (searchEl) {
            searchEl.addEventListener('input', function () {
                state.q = normalize(searchEl.value);
                state.page = 1;
                render();
            });
        }
        if (pagerEl) {
            pagerEl.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('[data-pager-dir]') : null;
                if (!btn || btn.disabled) { return; }
                state.page += parseInt(btn.getAttribute('data-pager-dir'), 10) || 0;
                render();
                var bar = document.querySelector('.list-filter-bar');
                if (bar && bar.scrollIntoView) { bar.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
            });
        }
        render();
    }

    ready(function () {
        convertServerAlerts();
        hookNavigationProgress();
        var ids = {};
        var nodes = document.querySelectorAll('[data-list-items]');
        for (var i = 0; i < nodes.length; i++) {
            ids[nodes[i].getAttribute('data-list-items')] = true;
        }
        Object.keys(ids).forEach(setupListWidget);
    });

    // API برنامه‌ای برای سایر اسکریپت‌ها
    window.showToast = showToast;
})();
