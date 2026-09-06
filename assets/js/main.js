/* ============================================================
 * مدیریت ساختمان پرو — اسکریپت مشترک همه صفحات (main.js)
 * این فایل در تمام صفحات لود می‌شود (از طریق includes/footer.php)
 * امکانات:
 *   1. محو خودکار پیام‌های موفقیت (data-auto-hide)
 *   2. حالت «در حال ارسال...» برای فرم‌هایی که data-loading دارند
 *   3. تأیید قبل از اقدامات حساس (data-confirm)
 *   4. حذف پیام‌های خطا با کلیک
 *   5. پاپ‌آپ‌های استاندارد (data-modal-open / data-modal-close)
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


        /* 6) پاپ‌آپ‌ها (Modal)
         *    باز کردن: هر عنصری با data-modal-open="modal-id"
         *    بستن:     دکمه با data-modal-close یا کلیک روی پس‌زمینه یا Esc
         *    پیش‌پر کردن فرم ویرایش: data-set-* روی دکمه، مقدار در فیلد هم‌نام
         */
        function openModal(id) {
            var overlay = document.getElementById(id);
            if (!overlay) {
                return;
            }
            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            var focusable = overlay.querySelector('input:not([type=hidden]), textarea, select');
            if (focusable) {
                setTimeout(function () { focusable.focus(); }, 280);
            }
        }

        function closeModal(overlay) {
            if (!overlay) {
                return;
            }
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            if (!document.querySelector('.modal-overlay.is-open')) {
                document.body.classList.remove('modal-open');
            }
        }

        window.openModal = openModal;

        document.querySelectorAll('[data-modal-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                var id = trigger.getAttribute('data-modal-open');
                var overlay = document.getElementById(id);
                if (!overlay) {
                    return;
                }

                /* پیش‌پر کردن فیلدهای فرم ویرایش از روی data-set-* دکمه */
                Array.prototype.forEach.call(trigger.attributes, function (attr) {
                    if (attr.name.indexOf('data-set-') !== 0) {
                        return;
                    }
                    var field = attr.name.slice('data-set-'.length);
                    var inputs = overlay.querySelectorAll('[name="' + field + '"]');
                    if (!inputs.length) {
                        return;
                    }
                    var input = inputs[0];

                    if (input.type === 'radio') {
                        /* گروه رادیو: گزینه‌ای که مقدارش برابر است انتخاب شود */
                        Array.prototype.forEach.call(inputs, function (radio) {
                            radio.checked = radio.value === attr.value;
                        });
                    } else if (input.type === 'checkbox') {
                        input.checked = attr.value === '1' || attr.value === 'true';
                    } else {
                        input.value = attr.value;
                    }

                    /* رویداد change تا منطق وابسته (مثل نمایش فیلد شارژ) به‌روز شود */
                    Array.prototype.forEach.call(inputs, function (el) {
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });

                openModal(id);
            });
        });

        document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal(btn.closest('.modal-overlay'));
            });
        });

        /* بستن با کلیک روی پس‌زمینه */
        document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    closeModal(overlay);
                }
            });
        });

        /* بستن با کلید Esc */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal(document.querySelector('.modal-overlay.is-open'));
            }
        });

        /* اگر فرم داخل پاپ‌آپ خطا داشت، پاپ‌آپ را دوباره باز کن */
        var reopen = document.body.getAttribute('data-reopen-modal');
        if (reopen) {
            openModal(reopen);
        }

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
