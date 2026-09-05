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
 * ============================================================ */

$page_title   = $page_title ?? 'مدیریت ساختمان';
$back_url     = $back_url ?? 'index.php';
$header_sub   = $header_sub ?? '';
$nav_active   = $nav_active ?? ($active_nav ?? 'none');
$alert_message = $alert_message ?? '';
$alert_type   = $alert_type ?? 'error';
$standalone   = $standalone ?? false;

// اگر تعداد اعلانات تعیین نشده بود، از API خوانده شود (نمایش نشان ناوبری و زنگ)
if (!isset($unread_nav) && function_exists('callAPI')) {
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
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> | مدیریت ساختمان</title>
    <!-- فونت وزیرمتن -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <!-- Tailwind برای کلاس‌های کاربردی -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- استایل استاندارد اپ -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="app-body">

    <div class="app-container">

        <?php if (!$standalone): ?>

        <!-- هدر برنامه -->
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

            <a href="notifications.php" class="notification-bell" aria-label="اعلانات">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                <?php if ($unread_nav > 0): ?>
                    <span class="badge"><?= fa_digits($unread_nav) ?></span>
                <?php endif; ?>
            </a>
        </header>

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
