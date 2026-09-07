/* ============================================================
 * Service Worker — اپلیکیشن وب پیش‌روندهٔ «مدیریت ساختمان»
 *
 * سیاست‌ها:
 *  - فایل‌های استاتیک (استایل/اسکریپت/آیکن): cache-first با به‌روزرسانی در پس‌زمینه
 *  - ناوبری صفحات PHP: network-first تا هیچ نسخهٔ کهنه‌ای از صفحهٔ لاگ‌شده
 *    به کاربر نمایش داده نشود؛ در قطعی شبکه، صفحهٔ آفلاین نشان داده می‌شود.
 *  - API و دانلود اسناد: هرگز کش نمی‌شوند (دادهٔ خصوصی و پویا).
 * ============================================================ */

var VERSION = 'bms-v1';
var STATIC_CACHE = VERSION + '-static';
var RUNTIME_CACHE = VERSION + '-runtime';

var PRECACHE = [
    'manifest.json',
    'offline.html',
    'assets/css/style.css',
    'assets/js/main.js',
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

    // استاتیک‌ها: cache-first با ذخیره‌سازی موفقیت‌ها در runtime
    event.respondWith(
        caches.match(request).then(function (cached) {
            if (cached) {
                return cached;
            }
            return fetch(request).then(function (response) {
                if (response.ok && (request.destination === 'style' ||
                    request.destination === 'script' ||
                    request.destination === 'image' ||
                    request.destination === 'font')) {
                    var copy = response.clone();
                    caches.open(RUNTIME_CACHE).then(function (cache) {
                        cache.put(request, copy);
                    });
                }
                return response;
            });
        })
    );
});
