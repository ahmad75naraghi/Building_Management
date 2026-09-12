<?php
/* ============================================================
 * هدر مشترک همه صفحات — نسخه استاندارد (includes/header.php)
 * شامل: <head> کامل (فونت + Tailwind + assets/css/style.css)
 *       هدر اپ تیره (بازگشت + عنوان + زنگ اعلان) و پیام هشدار
 *
 * متغیرهای اختیاری:
 *   $page_title     عنوان صفحه (پیش‌فرض: مدیریت ساختمان)
 *   $back_url       مقصد دکمه بازگشت (پیش‌فرض: index.php)
 *   $header_sub     زیرعنوان زیر عنوان
 *   $nav_active     آیتم فعال ناوبری: home|dashboard|tickets|profile|messages|none
 *                  (نام قدیمی $active_nav هم پذیرفته می‌شود)
 *   $unread_nav     تعداد اعلانات خوانده‌نشده — در صورت تعیین‌نشدن از API خوانده می‌شود
 *   $alert_message  متن پیام خطا/موفقیت (اختیاری)
 *   $alert_type     error|success (پیش‌فرض: error)
 *   $standalone     اگر true باشد فقط <head> و <body> بدون هدر اپ (برای صفحات ورود/ثبت‌نام)
 *   $reopen_modal   شناسه پاپ‌آپی که بعد از ارسال ناموفق فرم دوباره باز شود
 *   $header_variant اگر 'home' باشد، هدر اصلی (زنگ + عنوان + دکمه پروفایل) به‌جای هدر
 *                   صفحات داخلی (دکمه بازگشت) نمایش داده می‌شود.
 * ============================================================ */

$page_title   = $page_title ?? 'مدیریت ساختمان';
$back_url     = $back_url ?? 'index.php';
$header_sub   = $header_sub ?? '';
$nav_active   = $nav_active ?? ($active_nav ?? 'none');
$alert_message = $alert_message ?? '';
$alert_type   = $alert_type ?? 'error';
$standalone   = $standalone ?? false;
$header_variant = $header_variant ?? 'subpage';
$reopen_modal = $reopen_modal ?? '';

// کامپوننت پاپ‌آپ مشترک در همه صفحات در دسترس باشد
require_once __DIR__ . '/modal.php';

// اگر تعداد اعلانات تعیین نشده بود، از API خوانده شود (نمایش نشان ناوبری و زنگ)
// در صفحات بدون لاگین/بدون ناوبری (ورود، ثبت‌نام و…) نیازی به واکشی نیست.
if (!isset($unread_nav) && empty($standalone) && !empty($_SESSION['token']) && function_exists('callAPI')) {
    $unread_nav = 0;
    $notif_response = callAPI('GET', '/notifications');
    if (!empty($notif_response['success'])) {
        foreach ($notif_response['data'] ?? [] as $notif) {
            if (empty($notif['is_read'])) {
                $unread_nav++;
            }
        }
    }
}
if (!isset($unread_nav)) {
    $unread_nav = 0;
}

// تعداد پیام‌های نخواندهٔ صندوق پیام (برای نشان ناوبری) — اگر صفحه خودش نیاورده باشد
if (!isset($unread_messages_nav) && empty($standalone) && !empty($_SESSION['token']) && function_exists('callAPI')) {
    $unread_messages_nav = 0;
    $msg_building = (int) ($nav_building_id ?? ($building_id ?? ($_SESSION['active_building_id'] ?? 0)));
    if ($msg_building > 0) {
        $msg_response = callAPI('GET', '/messages/unread-count', ['building_id' => $msg_building]);
        if (!empty($msg_response['success'])) {
            $unread_messages_nav = (int) ($msg_response['data']['unread'] ?? 0);
        }
    }
}
if (!isset($unread_messages_nav)) {
    $unread_messages_nav = 0;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> | مدیریت ساختمان</title>
    <!-- حالت شب/روز: روشن، تاریک، یا «پیروی از سیستم» — اعمال پیش از رندر (جلوگیری از فلش) -->
    <script>
        (function () {
            var root = document.documentElement;
            var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

            function storedPref() {
                try { return localStorage.getItem('bm_theme') || 'auto'; }
                catch (e) { return 'auto'; }
            }
            function resolved(pref) {
                if (pref === 'dark') { return 'dark'; }
                if (pref === 'light') { return 'light'; }
                return (mq && mq.matches) ? 'dark' : 'light';
            }
            function applyTheme(mode) {
                if (mode === 'dark') { root.setAttribute('data-theme', 'dark'); }
                else { root.removeAttribute('data-theme'); }
                var meta = document.querySelector('meta[name="theme-color"]');
                if (meta) { meta.setAttribute('content', mode === 'dark' ? '#0b1322' : '#010a21'); }
            }

            applyTheme(resolved(storedPref()));

            /* در حالت خودکار، تغییر تنظیم سیستم بلافاصله اعمال شود */
            if (mq && mq.addEventListener) {
                mq.addEventListener('change', function () {
                    if (storedPref() === 'auto') { applyTheme(resolved('auto')); }
                });
            }

            window.__bmThemePref = storedPref;

            window.toggleAppTheme = function () {
                var order = ['light', 'dark', 'auto'];
                var cur = storedPref();
                var idx = order.indexOf(cur);
                var next = order[(idx + 1) % order.length];
                try { localStorage.setItem('bm_theme', next); }
                catch (e) { /* دسترسی به حافظهٔ مرورگر ممکن است بسته باشد */ }
                /* انیمیشن نرم انتقال رنگ‌ها */
                root.classList.add('theme-transition');
                window.setTimeout(function () { root.classList.remove('theme-transition'); }, 520);
                applyTheme(resolved(next));
                if (window.syncThemeButtons) { window.syncThemeButtons(); }
                var labels = { light: 'حالت نمایش: روشن', dark: 'حالت نمایش: تاریک', auto: 'حالت نمایش: پیروی از سیستم' };
                if (window.showToast) { window.showToast(labels[next], 'success'); }
            };
        })();
    </script>
    <!-- PWA: نصب اپلیکیشن روی موبایل/دسکتاپ -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#010a21">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="مدیریت ساختمان">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <!-- فونت وزیرمتن -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <!-- Tailwind برای کلاس‌های کاربردی -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- استایل استاندارد اپ -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="app-body"<?= $reopen_modal !== '' ? ' data-reopen-modal="' . htmlspecialchars($reopen_modal) . '"' : '' ?>>

    <div class="app-container">

        <?php if (!$standalone): ?>

        <!-- هدر برنامه -->
        <?php if ($header_variant === 'home'): ?>

        <header class="app-header">
            <div class="header-profile-section">
                <a href="notifications.php" class="notification-bell" aria-label="اعلانات">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <?php if ($unread_nav > 0): ?>
                        <span class="badge"><?= fa_digits($unread_nav) ?></span>
                    <?php endif; ?>
                </a>
                <button type="button" class="theme-toggle-btn" onclick="toggleAppTheme()" aria-label="تغییر حالت شب/روز">
                    <svg class="ico-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                    </svg>
                    <svg class="ico-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="4" />
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
                    </svg>
                </button>
            </div>

            <div class="header-title-text">
                <h1><?= htmlspecialchars($page_title) ?></h1>
                <?php if (!empty($header_sub)): ?>
                    <p><?= htmlspecialchars($header_sub) ?></p>
                <?php endif; ?>
            </div>

            <a href="profile.php" class="menu-hamburger" aria-label="پروفایل">
                <span></span>
                <span style="width: 14px; align-self: flex-start; margin-right: 12px;"></span>
                <span></span>
            </a>
        </header>

        <?php else: ?>

        <header class="app-header">
            <a href="<?= htmlspecialchars($back_url) ?>" class="subpage-back-btn" aria-label="بازگشت">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </a>

            <div class="subpage-title-wrap">
                <div class="subpage-title"><?= htmlspecialchars($page_title) ?></div>
                <?php if (!empty($header_sub)): ?>
                    <div class="subpage-sub"><?= htmlspecialchars($header_sub) ?></div>
                <?php endif; ?>
            </div>

            <div style="display:flex;align-items:center;gap:8px;">
                <button type="button" class="theme-toggle-btn" onclick="toggleAppTheme()" aria-label="تغییر حالت شب/روز">
                    <svg class="ico-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                    </svg>
                    <svg class="ico-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="4" />
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
                    </svg>
                </button>
                <a href="notifications.php" class="notification-bell" aria-label="اعلانات">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <?php if ($unread_nav > 0): ?>
                        <span class="badge"><?= fa_digits($unread_nav) ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </header>


        <?php endif; ?>

        <?php endif; ?>

        <?php if (!empty($alert_message)): ?>
        <!-- پیام خطا / موفقیت -->
        <div class="app-alert app-alert-<?= $alert_type === 'success' ? 'success' : 'error' ?>"<?= $alert_type === 'success' ? ' data-auto-hide' : '' ?>>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <?php if ($alert_type === 'success'): ?>
                    <path d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                <?php else: ?>
                    <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                <?php endif; ?>
            </svg>
            <span><?= htmlspecialchars($alert_message) ?></span>
        </div>
        <?php endif; ?>
