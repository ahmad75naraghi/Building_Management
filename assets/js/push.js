/**
 * اعلان فوری وب (Web Push) — سمت کاربر
 *
 * فعال‌سازی/غیرفعال‌سازی اشتراک پوش از پروفایل. ارسال اعلان‌ها با سرور است
 * (PushService)؛ این ماژول فقط اشتراک دستگاه را ثبت یا حذف می‌کند.
 *
 * نیازمندی‌ها: سرویس‌ورکر ثبت‌شده (pwa.js)، HTTPS و مرورگر پشتیبان.
 * در مرورگرهای فاقد پشتیبانی (مثل سافاری آی‌او‌اس)، دکمه پیام مناسب نشان می‌دهد
 * و هرگز خطایی به کاربر نمایش داده نمی‌شود.
 */
(function () {
    'use strict';

    var btn = document.getElementById('push-toggle-btn');
    var stateEl = document.getElementById('push-toggle-state');
    if (!btn) { return; }

    function token() {
        var m = document.querySelector('meta[name="bms-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function api(path, method, body) {
        var opts = {
            method: method || 'GET',
            headers: {
                'Authorization': 'Bearer ' + token(),
                'Content-Type': 'application/json'
            }
        };
        if (body) { opts.body = JSON.stringify(body); }
        return fetch('api/' + path, opts).then(function (r) {
            return r.json().catch(function () { return { success: false }; });
        }).catch(function () { return { success: false }; });
    }

    /** تبدیل کلید base64url سرور به Uint8Array برای pushManager.subscribe */
    function urlB64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var output = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; i++) { output[i] = rawData.charCodeAt(i); }
        return output;
    }

    function supported() {
        return 'serviceWorker' in navigator
            && 'PushManager' in window
            && 'Notification' in window
            && (window.location.protocol === 'https:' || window.location.hostname === 'localhost');
    }

    function setState(text, cls) {
        if (stateEl) {
            stateEl.textContent = text;
            stateEl.className = 'push-state-badge ' + (cls || '');
        }
    }

    function toast(message, type) {
        if (window.showToast) { window.showToast(message, type === 'error' ? 'error' : 'success'); }
    }

    function currentSubscription() {
        return navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription();
        });
    }

    function enable() {
        api('push/public-key').then(function (res) {
            if (!res || !res.enabled || !res.public_key) {
                toast('اعلان فوری در این سرور فعال نشده است (کلید VAPID لازم است)', 'error');
                setState('غیرفعال در سرور', 'off');
                return;
            }
            var publicKey = res.public_key;
            Notification.requestPermission().then(function (permission) {
                if (permission !== 'granted') {
                    toast('اجازهٔ نمایش اعلان داده نشد', 'error');
                    setState('بدون اجازه', 'off');
                    return;
                }
                navigator.serviceWorker.ready.then(function (reg) {
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlB64ToUint8Array(publicKey)
                    });
                }).then(function (subscription) {
                    return api('push/subscribe', 'POST', subscription.toJSON()).then(function (r) {
                        if (r && r.success) {
                            toast('اعلان فوری فعال شد 🎉');
                            setState('فعال ✓', 'on');
                            btn.setAttribute('data-push-on', '1');
                        } else {
                            toast((r && r.message) || 'ثبت اشتراک ناموفق بود', 'error');
                            subscription.unsubscribe();
                        }
                    });
                }).catch(function () {
                    toast('فعال‌سازی اعلان فوری در این مرورگر ممکن نشد', 'error');
                    setState('خطا در فعال‌سازی', 'off');
                });
            });
        });
    }

    function disable() {
        currentSubscription().then(function (subscription) {
            var endpoint = subscription ? subscription.endpoint : '';
            if (subscription) { subscription.unsubscribe(); }
            return api('push/unsubscribe', 'POST', { endpoint: endpoint });
        }).then(function () {
            toast('اعلان فوری غیرفعال شد');
            setState('غیرفعال', 'off');
            btn.removeAttribute('data-push-on');
        });
    }

    function init() {
        if (!supported()) {
            setState('پشتیبانی نمی‌شود', 'off');
            btn.style.opacity = '.55';
            btn.addEventListener('click', function () {
                toast('مرورگر شما از اعلان فوری پشتیبانی نمی‌کند', 'error');
            });
            return;
        }
        // وضعیت فعلی: اشتراک مرورگر + وضعیت سرور
        currentSubscription().then(function (sub) {
            if (sub) {
                setState('فعال ✓', 'on');
                btn.setAttribute('data-push-on', '1');
            } else {
                setState('غیرفعال', 'off');
            }
        }).catch(function () { setState('غیرفعال', 'off'); });

        btn.addEventListener('click', function () {
            if (btn.getAttribute('data-push-on') === '1') { disable(); } else { enable(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
