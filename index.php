<?php
require_once 'includes/api_helper.php';

// بررسی لاگین کاربر
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: login.php");
    exit;
}

// دریافت اطلاعات کاربر (نام واقعی از API)
$me_response = callAPI('GET', '/auth/me');
$userName = $me_response['data']['name'] ?? ($_SESSION['user_name'] ?? 'کاربر');
$userPhone = $me_response['data']['phone'] ?? '';

// دریافت اعلانات برای عدد زنگوله
$notif_response = callAPI('GET', '/notifications');
$unread_notifs = 0;
if (isset($notif_response['success']) && $notif_response['success'] === true) {
    foreach ($notif_response['data'] as $notif) {
        if (empty($notif['is_read'])) {
            $unread_notifs++;
        }
    }
}

// دریافت لیست ساختمان‌های کاربر (عضو یا مدیر)
$buildings = [];
$buildings_response = callAPI('GET', '/buildings');
if (isset($buildings_response['success']) && $buildings_response['success'] === true) {
    $buildings = is_array($buildings_response['data']) ? $buildings_response['data'] : [];
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ساختمان‌های من | مدیریت ساختمان</title>
    <!-- بارگذاری فونت زیبا و استاندارد وزیرمتن -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="app-body">

    <div class="app-container">

        <!-- هدر برنامه -->
        <header class="app-header">
            <div class="header-profile-section">
                <div class="notification-bell" onclick="window.location.href='notifications.php'" style="cursor:pointer;" role="link" aria-label="اعلانات">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <?php if ($unread_notifs > 0): ?>
                        <span class="badge"><?= $unread_notifs ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="header-title-text">
                <h1>ساختمان‌های من</h1>
                <p><?= htmlspecialchars($userName) ?> خوش آمدید 👋</p>
            </div>

            <div class="menu-hamburger" onclick="window.location.href='profile.php'" style="cursor:pointer;" role="link" aria-label="پروفایل">
                <span></span>
                <span style="width: 14px; align-self: flex-start; margin-right: 12px;"></span>
                <span></span>
            </div>
        </header>

        <!-- کارت خوش‌آمد -->
        <section class="building-hero-card">
            <div class="building-details-wrapper">
                <div class="building-header-row">
                    <div class="building-name-container">
                        <h2>انتخاب ساختمان</h2>
                        <p dir="ltr"><?= htmlspecialchars($userPhone) ?></p>
                    </div>
                    <div class="building-logo-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--gold-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="10" width="4" height="11" rx="1" />
                            <rect x="8" y="2" width="4" height="19" rx="1" />
                            <rect x="14" y="8" width="4" height="13" rx="1" />
                            <rect x="20" y="13" width="2" height="8" rx="1" />
                        </svg>
                    </div>
                </div>
                <div class="building-meta-specs" style="font-size: 10px; opacity: 0.8; margin-top: 4px;">
                    <?php if (empty($buildings)): ?>
                        هنوز در هیچ ساختمانی عضو نیستید. یک ساختمان جدید ثبت کنید یا از طریق لینک دعوت پیامکی عضو شوید.
                    <?php else: ?>
                        شما در <?= fa_digits(count($buildings)) ?> ساختمان عضو هستید. برای ادامه، یکی را انتخاب کنید.
                    <?php endif; ?>
                </div>
                <button class="btn-view-profile" onclick="window.location.href='building_add.php'">
                    <span>ثبت ساختمان جدید</span>
                    <svg width="6" height="10" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 9 1 5l4-4" />
                    </svg>
                </button>
            </div>
            <div class="building-image-wrapper">
                <img src="assets/img/buildings/b2.jpg" alt="ساختمان‌ها">
            </div>
        </section>

        <!-- لیست ساختمان‌ها -->
        <section class="quick-access-section">
            <div class="section-header-row">
                <h2 class="section-title">لیست ساختمان‌ها (<?= fa_digits(count($buildings)) ?>)</h2>
            </div>

            <?php if (empty($buildings)): ?>
                <div class="card empty-state">
                    <div class="text-4xl mb-3">🏢</div>
                    ساختمانی یافت نشد.<br>
                    با دکمه «ثبت ساختمان جدید» اولین ساختمان را بسازید.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($buildings as $b): ?>
                        <?php
                        $bid = (int) ($b['id'] ?? 0);
                        $role = $b['my_role'] ?? 'resident';
                        $is_mgr = ($role === 'manager');
                        ?>
                        <div class="card p-0 overflow-hidden" onclick="window.location.href='dashboard.php?building_id=<?= $bid ?>'" style="cursor:pointer;">
                            <div class="flex items-stretch gap-0">
                                <div class="flex-shrink-0" style="width:96px;">
                                    <img src="<?= htmlspecialchars(building_cover($b)) ?>" alt="<?= htmlspecialchars($b['name'] ?? '') ?>" style="width:96px;height:100%;min-height:96px;object-fit:cover;">
                                </div>
                                <div class="flex-1 min-w-0 p-4">
                                    <div class="flex items-center gap-2">
                                        <h3 class="font-bold text-gray-800 truncate"><?= htmlspecialchars($b['name'] ?? 'بدون نام') ?></h3>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1 truncate"><?= htmlspecialchars($b['address'] ?? '') ?></p>
                                    <div class="flex items-center gap-2 mt-2 flex-wrap">
                                        <span class="text-[10px] px-2.5 py-1 rounded-full <?= $is_mgr ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600' ?>">
                                            <?= htmlspecialchars(member_role_label($role)) ?>
                                        </span>
                                        <?php if (!empty($b['total_units'])): ?>
                                            <span class="text-[10px] px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700"><?= fa_digits($b['total_units']) ?> واحد</span>
                                        <?php endif; ?>
                                        <?php if (!empty($b['total_floors'])): ?>
                                            <span class="text-[10px] px-2.5 py-1 rounded-full bg-amber-50 text-amber-700"><?= fa_digits($b['total_floors']) ?> طبقه</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center pl-3 flex-shrink-0">
                                    <svg width="8" height="12" viewBox="0 0 6 10" fill="none" stroke="#9ca3af" stroke-width="2">
                                        <path d="M5 9 1 5l4-4" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- راهنما -->
        <section class="quick-access-section">
            <div class="card p-4 text-xs text-gray-500 leading-6">
                💡 اگر مدیر ساختمان برایتان لینک دعوت پیامک کرده است، روی همان لینک بزنید تا پس از ورود، عضو ساختمان شوید.
            </div>
        </section>

        <!-- ناوبری پایین صفحه (Navigation Bar) -->
        <nav class="bottom-nav-bar">
            <!-- پروفایل -->
            <div class="nav-item-link" onclick="window.location.href='profile.php'" style="cursor:pointer;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
                <span>پروفایل</span>
            </div>

            <!-- پیام‌ها -->
            <div class="nav-item-link" onclick="window.location.href='notifications.php'" style="cursor:pointer;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                </svg>
                <span>پیام‌ها</span>
                <?php if ($unread_notifs > 0): ?>
                    <span class="nav-badge" style="right: 18px;"><?= fa_digits($unread_notifs) ?></span>
                <?php endif; ?>
            </div>

            <!-- دکمه شناور وسط -->
            <div class="floating-action-button" onclick="window.location.href='building_add.php'" style="cursor:pointer;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
            </div>

            <!-- تقویم -->
            <div class="nav-item-link" onclick="window.location.href='calendar.php'" style="cursor:pointer;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                    <line x1="16" y1="2" x2="16" y2="6" />
                    <line x1="8" y1="2" x2="8" y2="6" />
                    <line x1="3" y1="10" x2="21" y2="10" />
                </svg>
                <span>تقویم</span>
            </div>

            <!-- ساختمان‌ها (فعال) -->
            <div class="nav-item-link active">
                <div class="active-pill-box">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        <polyline points="9 22 9 12 15 12 15 22" />
                    </svg>
                    <span>ساختمان‌ها</span>
                </div>
            </div>
        </nav>

    </div>

    <script src="assets/js/main.js"></script>
</body>

</html>
