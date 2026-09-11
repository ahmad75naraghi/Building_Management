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

    /* اسکلت ناوبری: اگر رفتن به صفحهٔ جدید کمی طول بکشد، اسکلت بارگذاری
       روی صفحهٔ فعلی ظاهر می‌شود تا کاربر بازخورد فوری ببیند. */
    var navSkelTimer = null;
    function scheduleNavSkeleton() {
        if (navSkelTimer || document.getElementById('nav-skeleton')) { return; }
        navSkelTimer = window.setTimeout(function () {
            navSkelTimer = null;
            if (document.getElementById('nav-skeleton')) { return; }
            var cards = '';
            for (var i = 0; i < 5; i++) { cards += '<div class="skel skel-card"></div>'; }
            var el = document.createElement('div');
            el.id = 'nav-skeleton';
            el.className = 'nav-skeleton-overlay';
            el.setAttribute('aria-hidden', 'true');
            el.innerHTML =
                '<div class="nav-skel-head">' +
                '  <div class="skel nav-skel-circle"></div>' +
                '  <div style="flex:1;">' +
                '    <div class="skel skel-line w-40" style="height:14px;"></div>' +
                '    <div class="skel skel-line w-60" style="height:10px;margin-bottom:0;"></div>' +
                '  </div>' +
                '</div>' + cards;
            document.body.appendChild(el);
        }, 220);
    }
    function clearNavSkeleton() {
        if (navSkelTimer) { window.clearTimeout(navSkelTimer); navSkelTimer = null; }
        var el = document.getElementById('nav-skeleton');
        if (el && el.parentNode) { el.parentNode.removeChild(el); }
    }

    function hookNavigationProgress() {
        // کلیک روی لینک‌های داخلی → شروع نوار پیشرفت + اسکلت ناوبری
        document.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!a) { return; }
            var href = a.getAttribute('href') || '';
            if (a.target === '_blank' || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
            if (href.indexOf('#') === 0 || href.indexOf('javascript:') === 0) { return; }
            if (a.hasAttribute('data-modal-open') || a.hasAttribute('data-modal-close')) { return; }
            progressStart();
            scheduleNavSkeleton();
        }, true);

        // ارسال فرم → نوار پیشرفت + اسکلت ناوبری + اسپینر روی دکمهٔ ارسال
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') { return; }
            progressStart();
            // فرم‌های گفتگو/جستجوی درجا صفحه را عوض نمی‌کنند؛ اسکلت نمی‌خواهند
            if (!form.hasAttribute('data-no-nav-skeleton')) { scheduleNavSkeleton(); }
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

        window.addEventListener('pageshow', function () { progressDone(); clearNavSkeleton(); });
    }

    /* ----------------------------------------------------------
     * ۴) برگهٔ تأیید پایین صفحه (با تکرار نام مورد)
     * ---------------------------------------------------------- */
    function confirmSheet(opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'sheet-overlay';
            var sheet = document.createElement('div');
            sheet.className = 'app-sheet';
            sheet.setAttribute('role', 'alertdialog');

            var title = document.createElement('h3');
            title.className = 'app-sheet-title';
            title.textContent = opts.title || 'آیا مطمئن هستید؟';

            sheet.appendChild(title);
            if (opts.name) {
                var name = document.createElement('p');
                name.className = 'app-sheet-name';
                name.textContent = opts.name;
                sheet.appendChild(name);
            }
            if (opts.message) {
                var msg = document.createElement('p');
                msg.className = 'app-sheet-message';
                msg.textContent = opts.message;
                sheet.appendChild(msg);
            }

            var row = document.createElement('div');
            row.className = 'app-sheet-actions';
            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'app-sheet-btn app-sheet-btn-cancel';
            cancelBtn.textContent = 'انصراف';
            var okBtn = document.createElement('button');
            okBtn.type = 'button';
            okBtn.className = 'app-sheet-btn app-sheet-btn-danger';
            okBtn.textContent = opts.confirmText || 'تأیید';
            row.appendChild(cancelBtn);
            row.appendChild(okBtn);
            sheet.appendChild(row);

            overlay.appendChild(sheet);
            document.body.appendChild(overlay);

            function close(result) {
                overlay.classList.remove('is-open');
                sheet.classList.remove('is-open');
                document.body.classList.remove('modal-open');
                document.removeEventListener('keydown', onKey);
                window.setTimeout(function () { overlay.remove(); }, 240);
                resolve(result);
            }
            function onKey(e) {
                if (e.key === 'Escape') { close(false); }
            }
            cancelBtn.addEventListener('click', function () { close(false); });
            okBtn.addEventListener('click', function () { close(true); });
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) { close(false); }
            });
            document.addEventListener('keydown', onKey);

            document.body.classList.add('modal-open');
            requestAnimationFrame(function () {
                overlay.classList.add('is-open');
                sheet.classList.add('is-open');
            });
            window.setTimeout(function () { okBtn.focus(); }, 260);
        });
    }

    /** فرم‌های دارای data-confirm-sheet: برگهٔ تأیید با نام مورد، به‌جای confirm مرورگر */
    function hookConfirmSheets() {
        document.addEventListener('click', function (e) {
            var form = e.target && e.target.closest ? e.target.closest('form[data-confirm-sheet]') : null;
            if (!form) { return; }
            if (form.dataset.sheetBusy === '1') { return; }
            e.preventDefault();
            e.stopPropagation();
            confirmSheet({
                title: form.getAttribute('data-sheet-title') || 'آیا مطمئن هستید؟',
                name: form.getAttribute('data-sheet-name') || '',
                message: form.getAttribute('data-confirm') || 'این عملیات قابل بازگشت نیست.',
                confirmText: form.getAttribute('data-sheet-confirm') || 'تأیید',
            }).then(function (ok) {
                if (ok) {
                    form.dataset.sheetBusy = '1';
                    form.submit();
                }
            });
        }, true);
    }

    /* ----------------------------------------------------------
     * ۵) اقدام گروهی روی پرداخت‌ها
     * ---------------------------------------------------------- */
    function setupBulkPayments() {
        var bar = document.getElementById('bulk-pay-bar');
        if (!bar) { return; }
        var checks = Array.prototype.slice.call(document.querySelectorAll('.bulk-check'));
        var all = document.getElementById('bulk-pay-all');
        var countEl = document.getElementById('bulk-pay-count');
        var confirmBtn = document.getElementById('bulk-pay-confirm');
        var confirmForm = document.getElementById('bulk-pay-confirm-form');
        if (checks.length === 0) { bar.hidden = true; return; }

        function selected() {
            return checks.filter(function (c) { return c.checked; });
        }

        function fillHolders() {
            var ids = selected().map(function (c) { return c.getAttribute('data-payment-id'); });
            var holders = document.querySelectorAll('[data-bulk-ids]');
            var html = ids.map(function (id) {
                return '<input type="hidden" name="payment_ids[]" value="' + id + '">';
            }).join('');
            for (var i = 0; i < holders.length; i++) { holders[i].innerHTML = html; }
            var rc = document.getElementById('bulk-reject-count');
            if (rc) { rc.textContent = faNum(ids.length); }
        }

        function refresh() {
            var sel = selected();
            bar.hidden = sel.length === 0;
            if (countEl) { countEl.textContent = faNum(sel.length); }
            fillHolders();
            if (all) { all.checked = sel.length === checks.length && sel.length > 0; }
        }

        checks.forEach(function (c) { c.addEventListener('change', refresh); });
        if (all) {
            all.addEventListener('change', function () {
                checks.forEach(function (c) { c.checked = all.checked; });
                refresh();
            });
        }
        if (confirmBtn && confirmForm) {
            confirmBtn.addEventListener('click', function () {
                var n = selected().length;
                if (n === 0) { return; }
                confirmSheet({
                    title: 'تأیید گروهی پرداخت‌ها',
                    message: faNum(n) + ' پرداخت انتخاب‌شده یک‌جا تأیید و به حساب واحدشان ثبت می‌شود.',
                    confirmText: 'تأیید پرداخت‌ها',
                }).then(function (ok) {
                    if (ok) { confirmForm.submit(); }
                });
            });
        }
        refresh();
    }

    /* ----------------------------------------------------------
     * ۶) هماهنگ‌سازی دکمه‌های حالت نمایش (روشن/تاریک/خودکار)
     * ---------------------------------------------------------- */
    var THEME_ICONS = {
        /* حالت فعلی روشن → کلیک به تاریک می‌برد */
        light: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" /></svg>',
        /* حالت فعلی تاریک → کلیک به خودکار می‌برد */
        dark: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" /></svg>',
        /* حالت خودکار (پیروی از سیستم) */
        auto: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor" stroke="none" /></svg>',
    };
    var THEME_TITLES = {
        light: 'حالت نمایش: روشن',
        dark: 'حالت نمایش: تاریک',
        auto: 'حالت نمایش: پیروی از سیستم',
    };

    function syncThemeButtons() {
        var pref = 'auto';
        try {
            if (window.__bmThemePref) { pref = window.__bmThemePref(); }
        } catch (e) { /* ignore */ }
        var icon = THEME_ICONS[pref] || THEME_ICONS.auto;
        var title = THEME_TITLES[pref] || THEME_TITLES.auto;
        var btns = document.querySelectorAll('.theme-toggle-btn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].innerHTML = icon;
            btns[i].setAttribute('title', title + ' — برای تغییر بزنید');
            btns[i].setAttribute('aria-label', title);
        }
    }
    window.syncThemeButtons = syncThemeButtons;

    /* ----------------------------------------------------------
     * ۷) دکمهٔ اصلی فرم‌ها: چسبان در دسترس شست
     * ---------------------------------------------------------- */
    /* بازکردن جعبهٔ «شروع گفتگوی جدید» از دکمهٔ حالت خالی */
    function hookNewChatButton() {
        var btns = document.querySelectorAll('[data-open-new-chat]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function () {
                var box = document.getElementById('new-conversation-box');
                if (!box) { return; }
                box.open = true;
                box.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }
    }

    function hookStickySubmit() {
        /* فقط در صفحه‌هایی با نوار پایین؛ وگرنه دکمه بی‌دلیل شناور می‌شود */
        if (!document.querySelector('.bottom-nav-bar')) { return; }
        var forms = document.querySelectorAll('main form, form.card, form.space-y-4');
        for (var i = 0; i < forms.length; i++) {
            var f = forms[i];
            if (f.closest('.modal-overlay') || f.closest('#toast-stack')) { continue; }
            var btn = f.querySelector('.btn-primary');
            if (btn && btn.closest('form') === f && btn.type === 'submit') {
                f.classList.add('sticky-submit');
            }
        }
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
        // چند کانتینر با یک شناسه = لیست گروه‌بندی‌شده (مثلاً اسناد بر اساس دسته)
        var itemsEls = Array.prototype.slice.call(document.querySelectorAll('[data-list-items="' + id + '"]'));
        if (!itemsEls.length) { return; }
        var searchEl = document.querySelector('[data-list-search="' + id + '"]');
        var pagerEl = document.querySelector('[data-list-pager="' + id + '"]');
        var countEl = document.querySelector('[data-list-count="' + id + '"]');

        var items = [];
        itemsEls.forEach(function (container) {
            Array.prototype.push.apply(items, Array.prototype.slice.call(container.children));
        });
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

            // در لیست‌های گروه‌بندی‌شده، گروهِ بدون آیتمِ قابل‌نمایش پنهان می‌شود
            itemsEls.forEach(function (container) {
                var anyVisible = false;
                Array.prototype.forEach.call(container.children, function (child) {
                    if (child.style.display !== 'none') { anyVisible = true; }
                });
                container.style.display = anyVisible ? '' : 'none';
                // عنوان گروه (مثل دستهٔ اسناد) همراه با فهرست خالی پنهان می‌شود
                var group = container.parentElement;
                if (group && group.classList && group.classList.contains('doc-group')) {
                    group.style.display = anyVisible ? '' : 'none';
                }
            });

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
        hookConfirmSheets();
        setupBulkPayments();
        hookNewChatButton();
        hookStickySubmit();
        syncThemeButtons();
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
