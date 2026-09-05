/* ============================================================
 * مدیریت ساختمان پرو — اسکریپت مشترک همه صفحات (main.js)
 * این فایل در تمام صفحات لود می‌شود (از طریق includes/footer.php)
 * امکانات:
 *   1. محو خودکار پیام‌های موفقیت (data-auto-hide)
 *   2. حالت «در حال ارسال...» برای فرم‌هایی که data-loading دارند
 *   3. تأیید قبل از اقدامات حساس (data-confirm)
 *   4. حذف پیام‌های خطا با کلیک
 * ============================================================ */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {

        /* 1) محو خودکار پیام‌های موفقیت بعد از ۵ ثانیه */
        document.querySelectorAll('[data-auto-hide]').forEach(function (el) {
            var delay = parseInt(el.getAttribute('data-hide-delay') || '5000', 10);
            setTimeout(function () {
                el.style.transition = 'opacity .4s';
                el.style.opacity = '0';
                setTimeout(function () {
                    if (el.parentNode) {
                        el.parentNode.removeChild(el);
                    }
                }, 450);
            }, delay);
        });

        /* 2) بستن پیام‌های خطا با کلیک */
        document.querySelectorAll('.app-alert-error').forEach(function (el) {
            el.style.cursor = 'pointer';
            el.addEventListener('click', function () {
                el.style.transition = 'opacity .3s';
                el.style.opacity = '0';
                setTimeout(function () {
                    if (el.parentNode) {
                        el.parentNode.removeChild(el);
                    }
                }, 350);
            });
        });

        /* 3) حالت لودینگ دکمه ارسال — فقط فرم‌هایی که data-loading دارند */
        document.querySelectorAll('form[data-loading]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('[type="submit"]');
                if (!btn || btn.disabled) {
                    return;
                }
                btn.disabled = true;
                btn.setAttribute('data-original-html', btn.innerHTML);
                btn.classList.add('opacity-70', 'cursor-not-allowed');
                btn.innerHTML = '<span class="btn-loading-spinner"></span> در حال ارسال...';
            });
        });

        /* 4) تأیید قبل از اقدامات حساس */
        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var msg = el.getAttribute('data-confirm') || 'آیا مطمئن هستید؟';
                if (!window.confirm(msg)) {
                    e.preventDefault();
                    return false;
                }
            });
        });

        /* 5) فعال‌سازی مجدد دکمه در صورت برگشت مرورگر */
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                document.querySelectorAll('button[type="submit"][disabled]').forEach(function (btn) {
                    btn.disabled = false;
                    var original = btn.getAttribute('data-original-html');
                    if (original) {
                        btn.innerHTML = original;
                        btn.classList.remove('opacity-70', 'cursor-not-allowed');
                    }
                });
            }
        });

    });
})();
