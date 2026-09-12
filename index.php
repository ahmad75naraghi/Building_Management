<?php
// فهرست ساختمان‌های کاربر — با قالب استاندارد اپ (includes/header.php + includes/footer.php)
require_once 'includes/api_helper.php';

// بررسی لاگین کاربر
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
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

// رویدادهای امروز در همه ساختمان‌های کاربر (برای ویجت داشبورد)
// برای جلوگیری از کندی، فقط ۵ ساختمان اول بررسی می‌شود
$today_g = date('Y-m-d');
$today_events = [];
foreach (array_slice($buildings, 0, 5) as $b) {
    $bid = (int) ($b['id'] ?? 0);
    if ($bid <= 0) {
        continue;
    }
    $m_response = callAPI('GET', '/meetings', ['building_id' => $bid]);
    if (!empty($m_response['success'])) {
        foreach (($m_response['data'] ?? []) as $meeting) {
            if (($meeting['status'] ?? '') === 'cancelled') {
                continue;
            }
            $raw = (string) ($meeting['meeting_date'] ?? '');
            if (substr($raw, 0, 10) !== $today_g) {
                continue;
            }
            $ts = strtotime($raw);
            $today_events[] = [
                'type' => 'meeting',
                'title' => $meeting['title'] ?: 'جلسه',
                'time' => $ts !== false ? date('H:i', $ts) : '',
                'building' => $b['name'] ?? '',
                'building_id' => $bid,
                'ts' => $ts !== false ? $ts : 0,
            ];
        }
    }
    $bk_response = callAPI('GET', '/bookings', ['building_id' => $bid]);
    if (!empty($bk_response['success'])) {
        foreach (($bk_response['data'] ?? []) as $booking) {
            if (in_array($booking['status'] ?? '', ['cancelled', 'completed'], true)) {
                continue;
            }
            if (substr((string) ($booking['booking_date'] ?? ''), 0, 10) !== $today_g) {
                continue;
            }
            $start = substr((string) ($booking['start_time'] ?? ''), 0, 5);
            $ts = strtotime($booking['booking_date'] . ' ' . ($booking['start_time'] ?: '09:00:00'));
            $today_events[] = [
                'type' => 'booking',
                'title' => 'رزرو مشاعات',
                'time' => $start,
                'building' => $b['name'] ?? '',
                'building_id' => $bid,
                'ts' => $ts !== false ? $ts : 0,
            ];
        }
    }
}
usort($today_events, fn($x, $y) => $x['ts'] <=> $y['ts']);

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

            <!-- رویدادهای امروز -->
            <?php if (!empty($today_events)): ?>
                <div class="card p-4" style="margin-bottom: 16px;">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-gray-800 text-sm">📅 رویدادهای امروز</h3>
                        <span class="text-[11px] text-gray-400"><?= jdate('l j F Y') ?></span>
                    </div>
                    <div class="space-y-2">
                        <?php foreach (array_slice($today_events, 0, 4) as $ev): ?>
                            <a href="calendar.php?building_id=<?= (int) $ev['building_id'] ?>"
                               class="flex items-center gap-3 p-2 rounded-xl bg-gray-50 hover:bg-blue-50 transition-colors">
                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 <?= $ev['type'] === 'meeting' ? 'bg-purple-400' : 'bg-green-400' ?>"></span>
                                <span class="flex-1 min-w-0">
                                    <span class="block text-sm font-bold text-gray-700 truncate"><?= htmlspecialchars($ev['title']) ?></span>
                                    <span class="block text-[11px] text-gray-400 truncate"><?= htmlspecialchars($ev['building']) ?></span>
                                </span>
                                <?php if ($ev['time'] !== ''): ?>
                                    <span class="text-[11px] font-bold text-blue-600 flex-shrink-0"><?= fa_digits($ev['time']) ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if (count($today_events) > 4): ?>
                            <a href="calendar.php" class="block text-center text-[11px] text-blue-600 font-bold pt-1">
                                و <?= fa_digits(count($today_events) - 4) ?> رویداد دیگر — مشاهده تقویم
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- لیست ساختمان‌ها -->
            <div class="section-header-row" style="margin-bottom: 12px;">
                <h2 class="section-title">لیست ساختمان‌ها (<?= fa_digits(count($buildings)) ?>)</h2>
            </div>

            <?php if (empty($buildings)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🏢</div>
                    ساختمانی یافت نشد.<br>
                    با ثبت اولین ساختمان، مدیریت را شروع کنید.
                    <a class="empty-action" href="building_add.php">🏢 ثبت ساختمان جدید</a>
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
