/* ============================================================
 * Service Worker — اپلیکیشن وب پیش‌روندهٔ «مدیریت ساختمان»
 *
 * سیاست‌ها:
 *  - فایل‌های استاتیک (استایل/اسکریپت/آیکن): کش با به‌روزرسانی در پس‌زمینه
 *    (stale-while-revalidate) تا هیچ نسخهٔ کهنه‌ای از استایل/اسکریپت باقی نماند
 *  - ناوبری صفحات PHP: network-first تا هیچ نسخهٔ کهنه‌ای از صفحهٔ لاگ‌شده
 *    به کاربر نمایش داده نشود؛ در قطعی شبکه، صفحهٔ آفلاین نشان داده می‌شود.
 *  - API، دانلود اسناد و فیش‌های واریزی: هرگز کش نمی‌شوند (دادهٔ خصوصی و پویا).
 *
 * نکتهٔ مهم: با هر تغییر ظاهری/رفتاری استایل یا اسکریپت‌ها باید VERSION
 * بالا برود تا کش‌های قدیمی کاربران در اولین بازدید پاک شود.
 * ============================================================ */

var VERSION = 'bms-v8';
var STATIC_CACHE = VERSION + '-static';
var RUNTIME_CACHE = VERSION + '-runtime';

var PRECACHE = [
    'manifest.json',
    'offline.html',
    'assets/css/style.css',
    'assets/js/main.js',
    'assets/js/ui-enhance.js',
    'assets/js/charts.js',
    'assets/js/push.js',
    'assets/js/pwa.js',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
    'assets/icons/icon-maskable-512.png',
    'assets/icons/apple-touch-icon.png'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(function (cache) {
            return cache.addAll(PRECACHE);
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (key) {
                    return key !== STATIC_CACHE && key !== RUNTIME_CACHE;
                }).map(function (key) {
                    return caches.delete(key);
                })
            );
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') {
        return; // POST/PUT/DELETE هرگز کش نمی‌شوند
    }

    var url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return; // منابع بیرونی (فونت/CDN) دست‌نخورده می‌مانند
    }

    // داده‌های خصوصی و پویا: بدون کش
    if (url.pathname.indexOf('/api/') !== -1 ||
        url.pathname.indexOf('document_download.php') !== -1 ||
        url.pathname.indexOf('receipt_download.php') !== -1 ||
        url.pathname.indexOf('reports_export.php') !== -1 ||
        url.pathname.indexOf('auth.php') !== -1 ||
        url.pathname.indexOf('storage/') !== -1) {
        return;
    }

    // ناوبری: network-first + صفحهٔ آفلاین
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).then(function (response) {
                return response;
            }).catch(function () {
                return caches.match('offline.html');
            })
        );
        return;
    }

    // استاتیک‌ها: کش + به‌روزرسانی در پس‌زمینه (stale-while-revalidate)
    // نسخهٔ کش‌شده بلافاصله نمایش داده می‌شود و نسخهٔ تازه در پس‌زمینه
    // جایگزین می‌گردد؛ بنابراین بارگذاری بعدی همیشه به‌روز است و دیگر
    // استایل/اسکریپت کهنه با صفحهٔ جدید ترکیب نمی‌شود.
    var cacheable = request.destination === 'style' ||
        request.destination === 'script' ||
        request.destination === 'image' ||
        request.destination === 'font';

    event.respondWith(
        caches.match(request).then(function (cached) {
            var refresh = cacheable
                ? fetch(request).then(function (response) {
                    if (response.ok) {
                        var copy = response.clone();
                        caches.open(RUNTIME_CACHE).then(function (cache) {
                            cache.put(request, copy);
                        });
                    }
                    return response;
                }).catch(function () { return null; })
                : null;

            if (cached) {
                // به‌روزرسانی پس‌زمینه حتی اگر سرویس‌ورکر زود بسته شود ادامه یابد
                if (refresh) { event.waitUntil(refresh); }
                return cached;
            }
            if (refresh) {
                return refresh.then(function (response) {
                    return response || fetch(request);
                });
            }
            return fetch(request);
        })
    );
});

/* ==================== اعلان فوری وب (Web Push) ==================== */
self.addEventListener('push', function (event) {
    var data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = {}; }
    var title = data.title || 'مدیریت ساختمان';
    var options = {
        body: data.body || '',
        dir: 'rtl',
        lang: 'fa',
        icon: 'assets/icons/icon-192.png',
        badge: 'assets/icons/icon-192.png',
        data: { url: data.url || 'dashboard.php' },
        vibrate: [120, 60, 120],
        tag: 'bms-push'
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var target = (event.notification.data && event.notification.data.url) || 'dashboard.php';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (var i = 0; i < list.length; i++) {
                var client = list[i];
                if ('focus' in client) {
                    client.navigate(target);
                    return client.focus();
                }
            }
            return clients.openWindow(target);
        })
    );
});
