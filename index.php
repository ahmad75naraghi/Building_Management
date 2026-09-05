<?php
// فهرست ساختمان‌های کاربر — با قالب استاندارد اپ (includes/header.php + includes/footer.php)
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
$unread_nav = 0;
if (isset($notif_response['success']) && $notif_response['success'] === true) {
    foreach ($notif_response['data'] as $notif) {
        if (empty($notif['is_read'])) {
            $unread_nav++;
        }
    }
}

// دریافت لیست ساختمان‌های کاربر (عضو یا مدیر)
$buildings = [];
$buildings_response = callAPI('GET', '/buildings');
if (isset($buildings_response['success']) && $buildings_response['success'] === true) {
    $buildings = is_array($buildings_response['data']) ? $buildings_response['data'] : [];
}

$page_title     = 'ساختمان‌های من';
$header_sub     = $userName . ' خوش آمدید 👋';
$header_variant = 'home';
$nav_active     = 'buildings';
require_once 'includes/header.php';
?>

        <!-- کارت معرفی / خوش‌آمد -->
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

        <main class="p-5">

            <!-- لیست ساختمان‌ها -->
            <div class="section-header-row" style="margin-bottom: 12px;">
                <h2 class="section-title">لیست ساختمان‌ها (<?= fa_digits(count($buildings)) ?>)</h2>
            </div>

            <?php if (empty($buildings)): ?>
                <div class="empty-state">
                    <div style="font-size: 34px; margin-bottom: 8px;">🏢</div>
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
                        <a class="building-list-card" href="dashboard.php?building_id=<?= $bid ?>">
                            <img class="building-list-thumb" src="<?= htmlspecialchars(building_cover($b)) ?>" alt="<?= htmlspecialchars($b['name'] ?? '') ?>">
                            <div class="building-list-body">
                                <h3 class="building-list-title"><?= htmlspecialchars($b['name'] ?? 'بدون نام') ?></h3>
                                <p class="building-list-address"><?= htmlspecialchars($b['address'] ?? 'بدون آدرس') ?></p>
                                <div class="building-list-chips">
                                    <span class="chip <?= $is_mgr ? 'chip-gold' : 'chip-gray' ?>"><?= htmlspecialchars(member_role_label($role)) ?></span>
                                    <?php if (!empty($b['total_units'])): ?>
                                        <span class="chip chip-green"><?= fa_digits($b['total_units']) ?> واحد</span>
                                    <?php endif; ?>
                                    <?php if (!empty($b['total_floors'])): ?>
                                        <span class="chip chip-amber"><?= fa_digits($b['total_floors']) ?> طبقه</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="building-list-chevron">
                                <svg width="8" height="12" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 9 1 5l4-4" />
                                </svg>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- راهنما -->
            <div class="hint-card" style="margin-top: 16px;">
                💡 اگر مدیر ساختمان برایتان لینک دعوت پیامک کرده است، روی همان لینک بزنید تا پس از ورود، عضو ساختمان شوید.
            </div>

        </main>

<?php require_once 'includes/footer.php'; ?>
