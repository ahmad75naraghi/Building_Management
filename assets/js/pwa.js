/* ============================================================
 * بنر نصب اپلیکیشن (PWA)
 *
 * قوانین نمایش:
 *  - اگر اپ قبلاً نصب شده باشد (appinstalled یا اجرای standalone) → هرگز نشان نده
 *  - اگر کاربر با ضربدر رد کرده باشد → تا «یک هفته» نشان نده، بعد دوباره پیشنهاد بده
 *  - در غیر این صورت و با در دسترس بودن پیشنهاد مرورگر → با انیمیشن و کوچک نمایش بده
 * ============================================================ */
(function () {
    'use strict';

    var DISMISS_KEY = 'bms_pwa_dismissed_at';
    var INSTALLED_KEY = 'bms_pwa_installed';
    var ONE_WEEK_MS = 7 * 24 * 60 * 60 * 1000;

    /* ---------- ثبت سرویس‌ورکر (برای نصب‌پذیری و حالت آفلاین) ---------- */
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function () {
                /* نصب بنر نباید به خطای SW وابسته باشد */
            });
        });
    }

    var banner = document.getElementById('pwa-banner');
    if (!banner) {
        return;
    }
    var installBtn = document.getElementById('pwa-banner-install');
    var closeBtn = document.getElementById('pwa-banner-close');
    var subText = document.getElementById('pwa-banner-sub');

    var deferredPrompt = null;
    var shown = false;

    /* ---------- تشخیص اجرای داخل اپ نصب‌شده ---------- */
    function isStandalone() {
        if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
            return true;
        }
        if (window.navigator.standalone === true) { // iOS Safari
            return true;
        }
        return false;
    }

    function isInstalled() {
        try {
            return localStorage.getItem(INSTALLED_KEY) === '1' || isStandalone();
        } catch (err) {
            return isStandalone();
        }
    }

    /* اگر کاربر با ضربدر رد کرده باشد تا یک هفته سکوت می‌کنیم */
    function isDismissed() {
        try {
            var at = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
            return at > 0 && (Date.now() - at) < ONE_WEEK_MS;
        } catch (err) {
            return false;
        }
    }

    function isIOS() {
        return /iphone|ipad|ipod/i.test(window.navigator.userAgent || '') ||
            (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
    }

    function showBanner() {
        if (shown || isInstalled() || isDismissed()) {
            return;
        }
        shown = true;
        /* در iOS پیشنهاد خودکار مرورگر نداریم؛ راهنمای دستی نشان می‌دهیم */
        if (!deferredPrompt && isIOS()) {
            if (subText) {
                subText.textContent = 'از دکمهٔ اشتراک مرورگر، گزینهٔ «Add to Home Screen» را بزنید.';
            }
            if (installBtn) { installBtn.style.display = 'none'; }
        }
        setTimeout(function () {
            banner.classList.add('is-visible');
        }, 1600);
    }

    function hideBanner() {
        shown = false;
        banner.classList.remove('is-visible');
    }

    /* ---------- پیشنهاد نصب مرورگر ---------- */
    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        showBanner();
    });

    /* ---------- پس از نصب موفق: برای همیشه مخفی ---------- */
    window.addEventListener('appinstalled', function () {
        try {
            localStorage.setItem(INSTALLED_KEY, '1');
            localStorage.removeItem(DISMISS_KEY);
        } catch (err) { /* دسترسی به حافظه ضروری نیست */ }
        deferredPrompt = null;
        hideBanner();
    });

    if (installBtn) {
        installBtn.addEventListener('click', function () {
            if (!deferredPrompt) {
                hideBanner();
                return;
            }
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                if (choice && choice.outcome === 'accepted') {
                    try { localStorage.setItem(INSTALLED_KEY, '1'); } catch (err) { /* ignore */ }
                    hideBanner();
                }
                deferredPrompt = null;
                hideBanner();
            });
        });
    }

    /* ---------- ضربدر: رد کردن تا یک هفته ---------- */
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            try {
                localStorage.setItem(DISMISS_KEY, String(Date.now()));
            } catch (err) { /* ignore */ }
            hideBanner();
        });
    }

    /* ---------- شروع: کمی تأخیر تا مزاحم بارگذاری اولیه نباشد ---------- */
    if (!isInstalled() && !isDismissed()) {
        /* در iOS که beforeinstallprompt نداریم، بعد از بارگذاری پیشنهاد راهنما می‌دهیم */
        window.addEventListener('load', function () {
            setTimeout(showBanner, 1200);
        });
    }
})();
