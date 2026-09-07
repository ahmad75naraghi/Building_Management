<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

// دریافت آیدی ساختمان از URL
$building_id = (int) ($_GET['id'] ?? $_SESSION['active_building_id'] ?? 0);
if ($building_id <= 0) {
    header("Location: index.php");
    exit;
}

// نقش کاربر جاری (برای اکشن‌های مدیریتی روی واحدها)
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

// ---- حذف واحد از پاپ‌آپ نمای گرافیکی (فقط مدیر) — سپس ریدایرکت برای جلوگیری از ثبت مجدد ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'delete_unit') {
    $unit_id = (int) ($_POST['unit_id'] ?? 0);
    if (!$is_manager) {
        header("Location: building_view.php?id={$building_id}&flash=forbidden");
        exit;
    }
    $del_response = callAPI('DELETE', '/units/' . $unit_id);
    header("Location: building_view.php?id={$building_id}&flash=" . (!empty($del_response['success']) ? 'unit_deleted' : 'unit_delete_failed'));
    exit;
}

// پیام‌های ریدایرکت (PRG)
$flash_messages = [
    'unit_deleted' => ['واحد با موفقیت حذف شد.', 'success'],
    'unit_delete_failed' => ['خطا در حذف واحد.', 'error'],
    'forbidden' => ['این عملیات فقط برای مدیر ساختمان مجاز است.', 'error'],
];
$alert_message = '';
$alert_type = 'error';
if (isset($_GET['flash'], $flash_messages[$_GET['flash']])) {
    [$alert_message, $alert_type] = $flash_messages[$_GET['flash']];
}

// ---------- دریافت دیتای واقعی از API ----------

// اطلاعات ساختمان
$building = [];
$building_response = callAPI('GET', '/buildings/' . $building_id);
if (isset($building_response['success']) && $building_response['success'] === true) {
    $building = $building_response['data'] ?? [];
}

// اعضا
$members = [];
$members_response = callAPI('GET', '/buildings/' . $building_id . '/members');
if (isset($members_response['success']) && $members_response['success'] === true) {
    $members = $members_response['data'] ?? [];
}

// واحدها
$units = [];
$units_response = callAPI('GET', '/buildings/' . $building_id . '/units');
if (isset($units_response['success']) && $units_response['success'] === true) {
    $units = $units_response['data']['units'] ?? [];
}

// بلوک‌ها
$blocks = [];
$blocks_response = callAPI('GET', '/buildings/' . $building_id . '/blocks');
if (isset($blocks_response['success']) && $blocks_response['success'] === true) {
    $blocks = $blocks_response['data']['blocks'] ?? [];
}

// طبقات
$floors = [];
$floors_response = callAPI('GET', '/buildings/' . $building_id . '/floors');
if (isset($floors_response['success']) && $floors_response['success'] === true) {
    $floors = $floors_response['data']['floors'] ?? [];
}

// مشاعات
$common_areas = [];
$common_areas_response = callAPI('GET', '/buildings/' . $building_id . '/common-areas');
if (isset($common_areas_response['success']) && $common_areas_response['success'] === true) {
    $common_areas = $common_areas_response['data']['common_areas'] ?? [];
}

// خلاصه مالی
$financial = [];
$summary_response = callAPI('GET', '/costs/summary', ['building_id' => $building_id]);
if (isset($summary_response['success']) && $summary_response['success'] === true) {
    $financial = $summary_response['data'] ?? [];
}

// تیکت‌ها
$tickets = [];
$tickets_response = callAPI('GET', '/tickets', ['building_id' => $building_id]);
if (isset($tickets_response['success']) && $tickets_response['success'] === true) {
    $tickets = $tickets_response['data'] ?? [];
}
$open_tickets = 0;
foreach ($tickets as $t) {
    if (in_array($t['status'] ?? '', ['open', 'in_progress'], true)) {
        $open_tickets++;
    }
}

// اعلانات خوانده‌نشده (برای نشان ناوبری)
$unread_nav = 0;
$notifications_response = callAPI('GET', '/notifications');
if (isset($notifications_response['success']) && $notifications_response['success'] === true) {
    foreach ($notifications_response['data'] ?? [] as $n) {
        if (empty($n['is_read'])) {
            $unread_nav++;
        }
    }
}

// ---------- ساختار گرافیکی ساختمان: بلوک ← طبقه ← واحد ----------

$blocks_by_id = [];
foreach ($blocks as $b) {
    $blocks_by_id[(int) ($b['id'] ?? 0)] = $b;
}
$floors_by_id = [];
foreach ($floors as $f) {
    $floors_by_id[(int) ($f['id'] ?? 0)] = $f;
}

// گروه‌بندی واحدها بر اساس بلوک و طبقه
$units_by_block_floor = [];
foreach ($units as $u) {
    $bid = (int) ($u['block_id'] ?? 0);
    $fid = (int) ($u['floor_id'] ?? 0);
    $units_by_block_floor[$bid][$fid][] = $u;
}

// برچسب طبقه: نام یا شماره
$floor_label = static function (?array $floor): string {
    if (!$floor) {
        return 'بدون طبقه';
    }
    if (!empty($floor['name'])) {
        return (string) $floor['name'];
    }
    $n = $floor['floor_number'] ?? null;
    if ($n === null || $n === '') {
        return 'بدون طبقه';
    }
    $n = (int) $n;
    if ($n === 0) {
        return 'همکف';
    }
    if ($n < 0) {
        return 'زیرزمین ' . fa_digits(abs($n));
    }
    return 'طبقه ' . fa_digits($n);
};

// ساخت برج‌ها: هر بلوک معرفی‌شده + واحدهای بدون بلوک در یک برج جداگانه
$towers = [];
foreach ($blocks as $b) {
    $bid = (int) $b['id'];
    $towers[] = [
        'id' => $bid,
        'name' => $b['name'] ?? ('بلوک ' . fa_digits($bid)),
        'subtitle' => $b['description'] ?? null,
        'units_by_floor' => $units_by_block_floor[$bid] ?? [],
    ];
}
if (!empty($units_by_block_floor[0])) {
    $towers[] = [
        'id' => 0,
        'name' => count($towers) > 0 ? 'بدون بلوک' : ($building['name'] ?? 'ساختمان'),
        'subtitle' => null,
        'units_by_floor' => $units_by_block_floor[0],
    ];
}
// ساختمانی که نه بلوک دارد نه طبقه: همه واحدها در یک برج تک‌ردیف
if (!$towers && !empty($units)) {
    $towers[] = [
        'id' => 0,
        'name' => $building['name'] ?? 'ساختمان',
        'subtitle' => null,
        'units_by_floor' => [0 => $units],
    ];
}

// ردیف‌های هر برج: طبقات از بالا به پایین + ردیف واحدهای بدون طبقه
$build_rows = static function (array $units_by_floor, array $floors_by_id, int $towerId, bool $ignoreBlock, int $towerCount) use ($floor_label): array {
    $tower_floor_ids = array_map('intval', array_keys($units_by_floor));
    $defined = [];
    foreach ($floors_by_id as $fid => $floor) {
        if (!in_array((int) $fid, $tower_floor_ids, true)) {
            continue;
        }
        if (!$ignoreBlock) {
            $floorBlock = (int) ($floor['block_id'] ?? 0);
            $blockMatch = ($towerId === 0 && $floorBlock === 0) || ($towerId !== 0 && $floorBlock === $towerId);
            if (!$blockMatch && $towerCount !== 1) {
                continue;
            }
        }
        $defined[(int) $fid] = $floor;
    }
    uasort($defined, static function ($a, $b) {
        return ((int) ($b['floor_number'] ?? 0)) <=> ((int) ($a['floor_number'] ?? 0));
    });
    $rows = [];
    $used = [];
    foreach ($defined as $fid => $floor) {
        $rows[] = ['label' => $floor_label($floor), 'units' => $units_by_floor[$fid]];
        $used[] = (int) $fid;
    }
    $rest = [];
    foreach ($units_by_floor as $fid => $list) {
        if (!in_array((int) $fid, $used, true)) {
            foreach ($list as $u) {
                $rest[] = $u;
            }
        }
    }
    if ($rest) {
        $rows[] = ['label' => 'بدون طبقه', 'units' => $rest];
    }
    return $rows;
};

foreach ($towers as $ti => $tower) {
    if (empty($tower['units_by_floor'])) {
        $towers[$ti]['rows'] = [];
        continue;
    }
    $rows = $build_rows($tower['units_by_floor'], $floors_by_id, (int) $tower['id'], false, count($towers));
    // اگر هیچ ردیف طبقه‌ای ساخته نشد (مثلاً طبقات به بلوک متصل نیستند)، اتصال بلوک نادیده گرفته می‌شود
    $onlyRest = count($rows) === 1 && ($rows[0]['label'] ?? '') === 'بدون طبقه';
    if ($onlyRest) {
        $relaxed = $build_rows($tower['units_by_floor'], $floors_by_id, (int) $tower['id'], true, count($towers));
        if (count($relaxed) > 1 || ($relaxed && ($relaxed[0]['label'] ?? '') !== 'بدون طبقه')) {
            $rows = $relaxed;
        }
    }
    $towers[$ti]['rows'] = $rows;
}

// برچسب و آیکون نوع واحد
$unit_type_view = static function (?string $type): array {
    $map = [
        'residential' => ['مسکونی', '🏠'],
        'commercial' => ['تجاری', '🏪'],
        'office' => ['اداری', '💼'],
        'parking' => ['پارکینگ', '🚗'],
        'storage' => ['انباری', '📦'],
    ];
    return $map[$type] ?? ['مسکونی', '🏠'];
};
$occ_class_map = [
    'owner_occupied' => 'occ-owner',
    'tenant_occupied' => 'occ-tenant',
    'vacant' => 'occ-vacant',
    'no_owner' => 'occ-none',
];
$occ_label_map = [
    'owner_occupied' => 'مالک ساکن است',
    'tenant_occupied' => 'مستأجر ساکن است',
    'vacant' => 'خالی از سکنه',
    'no_owner' => 'بدون مالک',
];

// ماندهٔ مالی هر واحد (بدهکار/طلبکار) از پرداخت‌های تأییدشده
$unit_balances = [];
$balances_response = callAPI('GET', '/buildings/' . $building_id . '/unit-balances');
if (!empty($balances_response['success'])) {
    foreach ($balances_response['data'] ?? [] as $bal) {
        $unit_balances[(int) $bal['unit_id']] = $bal;
    }
}

// داده‌های پاپ‌آپ هر واحد (برای رندر سمت کلاینت)
$unit_popup_data = [];
foreach ($units as $u) {
    $uid = (int) ($u['id'] ?? 0);
    [$type_label, $type_icon] = $unit_type_view($u['type'] ?? 'residential');
    $occ_status = $u['occupancy_status'] ?? 'no_owner';
    $floor = $floors_by_id[(int) ($u['floor_id'] ?? 0)] ?? null;
    $block = $blocks_by_id[(int) ($u['block_id'] ?? 0)] ?? null;
    $unit_popup_data[$uid] = [
        'number' => $u['unit_number'] ?? '',
        'type_label' => $type_label,
        'type_icon' => $type_icon,
        'area' => $u['area'] ?? null,
        'floor_label' => $floor_label($floor),
        'block_label' => $block['name'] ?? null,
        'occ_class' => $occ_class_map[$occ_status] ?? 'occ-none',
        'occ_label' => $occ_label_map[$occ_status] ?? 'نامشخص',
        'occupant_name' => $u['occupant_name'] ?? null,
        'owner_name' => $u['owner_name'] ?? null,
        'owner_phone' => $u['owner_phone'] ?? null,
        'tenant_name' => $u['tenant_name'] ?? null,
        'tenant_phone' => $u['tenant_phone'] ?? null,
        'residents_count' => (int) ($u['residents_count'] ?? 0),
        'parking_no' => $u['parking_no'] ?? null,
        'storage_no' => $u['storage_no'] ?? null,
        'custom_charge' => $u['custom_charge'] ?? null,
        'balance' => $unit_balances[$uid] ?? null,
    ];
}

$page_title = $building['name'] ?? 'پروفایل ساختمان';
$header_sub = 'مدیریت ساختمان';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$unread_nav = $unread_nav ?? 0;
require_once 'includes/dash_head.php';
?>

        <!-- کارت معرفی ساختمان -->
        <section class="building-hero-card">
            <div class="building-details-wrapper">
                <div class="building-header-row">
                    <div class="building-name-container">
                        <h2>
                            <?= htmlspecialchars($building['name'] ?? 'ساختمان') ?>
                            <svg width="10" height="6" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m1 1 4 4 4-4" />
                            </svg>
                        </h2>
                        <p><?= htmlspecialchars($building['custom_name'] ?? 'مجتمع مسکونی') ?></p>
                    </div>
                    <div class="building-logo-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="<?= htmlspecialchars($building['theme_color'] ?? 'var(--gold-primary)') ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="10" width="4" height="11" rx="1" />
                            <rect x="8" y="2" width="4" height="19" rx="1" />
                            <rect x="14" y="8" width="4" height="13" rx="1" />
                            <rect x="20" y="13" width="2" height="8" rx="1" />
                        </svg>
                    </div>
                </div>
                <div class="building-meta-specs" style="font-size: 10px; opacity: 0.8; margin-top: 4px;">
                    <?= htmlspecialchars($building['address'] ?? 'آدرس ثبت نشده است') ?>
                </div>
                <button class="btn-view-profile" onclick="window.location.href='costs.php?building_id=<?= $building_id ?>'">
                    <span>مدیریت مالی و شارژ</span>
                    <svg width="6" height="10" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 9 1 5l4-4" />
                    </svg>
                </button>
                <div style="display:flex; gap:8px; margin-top:8px;">
                    <button class="btn-view-profile" style="flex:1;" onclick="window.location.href='building_edit.php?id=<?= $building_id ?>'">
                        <span>ویرایش اطلاعات</span>
                    </button>
                    <button class="btn-view-profile" style="flex:1; opacity:.85;" onclick="if(confirm('آیا مطمئن هستید؟ این ساختمان حذف می‌شود.')) window.location.href='building_delete.php?id=<?= $building_id ?>'">
                        <span style="color:#f87171;">حذف ساختمان</span>
                    </button>
                </div>
            </div>
            <div class="building-image-wrapper">
                <img src="<?= htmlspecialchars(building_cover($building ?? [])) ?>" alt="<?= htmlspecialchars($building['name'] ?? 'ساختمان') ?>">
            </div>
        </section>

        <!-- کارت آمار ۴ ستونه -->
        <section class="statistics-grid-card">
            <div class="stat-column">
                <div class="stat-icon-box purple">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                </div>
                <div class="stat-numeric-value"><?= fa_digits(count($members)) ?></div>
                <div class="stat-text-label">اعضای ساختمان</div>
                <div class="stat-growth-rate link">
                    <?php if (!empty($members)): ?>
                        <?= htmlspecialchars($members[0]['name'] ?? '') ?>
                    <?php else: ?>
                        دعوت ساکنین
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-column">
                <div class="stat-icon-box orange">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                </div>
                <div class="stat-numeric-value"><?= fa_digits($open_tickets) ?></div>
                <div class="stat-text-label">تیکت‌های باز</div>
                <div class="stat-growth-rate link">مشاهده تیکت‌ها</div>
            </div>

            <div class="stat-column">
                <div class="stat-icon-box green">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="4" width="20" height="16" rx="2" />
                        <line x1="12" y1="20" x2="12" y2="4" />
                    </svg>
                </div>
                <div class="stat-numeric-value"><?= fa_number($financial['total_remaining'] ?? 0) ?></div>
                <div class="stat-text-label">مانده حساب</div>
                <div class="stat-growth-rate down">
                    <?= fa_digits((int) round((float) ($financial['collection_percentage'] ?? 0))) ?>٪ وصول
                </div>
            </div>

            <div class="stat-column">
                <div class="stat-icon-box blue">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 21h18" />
                        <path d="M5 21V7l8-4v18" />
                        <path d="M19 21V11l-6-4" />
                        <path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01" />
                    </svg>
                </div>
                <div class="stat-numeric-value"><?= fa_digits(count($units)) ?></div>
                <div class="stat-text-label">واحدها</div>
                <div class="stat-growth-rate link"><?= fa_digits(count($blocks)) ?> بلوک</div>
            </div>
        </section>

        <!-- دسترسی سریع -->
        <section class="quick-access-section">
            <div class="section-header-row">
                <span class="section-title">دسترسی سریع</span>
            </div>
            <div class="quick-buttons-row">
                <a href="costs.php?building_id=<?= $building_id ?>" class="quick-btn-item">
                    <span style="font-size: 20px;">💰</span>
                    <span class="tile-label">مالی</span>
                </a>
                <a href="tickets.php?building_id=<?= $building_id ?>" class="quick-btn-item">
                    <span style="font-size: 20px;">🎫</span>
                    <span class="tile-label">تیکت</span>
                </a>
                <a href="announcements.php?building_id=<?= $building_id ?>" class="quick-btn-item">
                    <span style="font-size: 20px;">📢</span>
                    <span class="tile-label">اطلاعیه</span>
                </a>
                <a href="maintenance.php?building_id=<?= $building_id ?>" class="quick-btn-item">
                    <span style="font-size: 20px;">🔧</span>
                    <span class="tile-label">تعمیرات</span>
                </a>
                <a href="members.php?building_id=<?= $building_id ?>" class="quick-btn-item">
                    <span style="font-size: 20px;">👥</span>
                    <span class="tile-label">اعضا</span>
                </a>
            </div>
        </section>

        <!-- ساختار مجتمع -->
        <section class="quick-access-section">
            <div class="section-header-row">
                <span class="section-title">ساختار مجتمع</span>
                <a href="members.php?building_id=<?= $building_id ?>" class="widget-view-all-link"><?= fa_digits(count($members)) ?> عضو</a>
            </div>
            <div class="tile-grid">
                <a href="blocks.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #eef2ff; color: #6366f1;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" />
                        </svg>
                    </div>
                    <span class="tile-label">بلوک‌ها</span>
                </a>
                <a href="floors.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #ecfdf5; color: #10b981;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                        </svg>
                    </div>
                    <span class="tile-label">طبقات</span>
                </a>
                <a href="units.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #fefce8; color: #eab308;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 12l2-2m0 0 7-7 7 7M5 10v10a1 1 0 0 0 1 1h3m10-11l2 2m-2-2v10a1 1 0 0 1-1 1h-3m-6 0a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1m-6 0h6" />
                        </svg>
                    </div>
                    <span class="tile-label">واحدها</span>
                </a>
                <a href="common_areas.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #f5f3ff; color: #8b5cf6;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16 2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                        </svg>
                    </div>
                    <span class="tile-label">مشاعات</span>
                </a>
            </div>
        </section>

        <!-- نمای گرافیکی ساختمان -->
        <section class="quick-access-section">
            <div class="section-header-row">
                <span class="section-title">🏢 نمای ساختمان</span>
                <a href="units.php?building_id=<?= $building_id ?>" class="widget-view-all-link"><?= fa_digits(count($units)) ?> واحد</a>
            </div>

            <?php if (empty($units)): ?>
                <div class="bldg-card">
                    <div class="bldg-empty">
                        <div class="bldg-empty-ico">🏗️</div>
                        <p style="font-size:12px;color:var(--text-gray);">هنوز بلوک، طبقه یا واحدی معرفی نشده است.<br>برای ساخت نمای ساختمان، ساختار را تعریف کنید.</p>
                        <div class="bldg-empty-actions">
                            <a href="blocks.php?building_id=<?= $building_id ?>" class="btn-chip btn-chip-edit">تعریف بلوک‌ها</a>
                            <a href="floors.php?building_id=<?= $building_id ?>" class="btn-chip btn-chip-edit">تعریف طبقات</a>
                            <a href="units.php?building_id=<?= $building_id ?>" class="btn-chip btn-chip-success">افزودن واحد</a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="bldg-card">
                    <div class="bldg-scene">
                        <?php foreach ($towers as $tower): ?>
                            <div class="bldg-tower">
                                <div class="bldg-roof">
                                    <?= htmlspecialchars($tower['name']) ?>
                                    <?php if (!empty($tower['subtitle'])): ?>
                                        <small><?= htmlspecialchars($tower['subtitle']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="bldg-body">
                                    <?php if (empty($tower['rows'])): ?>
                                        <div class="bldg-floor">
                                            <div class="bldg-floor-label">—</div>
                                            <div class="bldg-units"><div class="bldg-floor-empty">واحدی ندارد</div></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php foreach ($tower['rows'] as $row): ?>
                                        <div class="bldg-floor">
                                            <div class="bldg-floor-label"><?= htmlspecialchars($row['label']) ?></div>
                                            <div class="bldg-units">
                                                <?php foreach ($row['units'] as $u): ?>
                                                    <?php
                                                    $u_id = (int) ($u['id'] ?? 0);
                                                    $u_occ = $occ_class_map[$u['occupancy_status'] ?? 'no_owner'] ?? 'occ-none';
                                                    [$u_type_label, $u_type_icon] = $unit_type_view($u['type'] ?? 'residential');
                                                    $u_tip = 'واحد ' . ($u['unit_number'] ?? '') . ' — ' . $u_type_label . ' — ' . ($occ_label_map[$u['occupancy_status'] ?? ''] ?? '');
                                                    ?>
                                                    <div class="bldg-unit <?= $u_occ ?>" role="button" tabindex="0"
                                                         data-unit-open="<?= $u_id ?>"
                                                         title="<?= htmlspecialchars($u_tip) ?>">
                                                        <span class="bldg-occ-dot <?= $u_occ ?>"></span>
                                                        <span class="bldg-num"><?= fa_digits(htmlspecialchars($u['unit_number'] ?? '')) ?></span>
                                                        <span class="bldg-type-ico"><?= $u_type_icon ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="bldg-ground">
                                    <span class="bldg-shrub"></span>
                                    <span class="bldg-door"></span>
                                    <span class="bldg-shrub"></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="bldg-legend">
                        <span class="bldg-legend-item"><i style="background:#10b981;"></i> مالک ساکن</span>
                        <span class="bldg-legend-item"><i style="background:#0ea5e9;"></i> مستأجر ساکن</span>
                        <span class="bldg-legend-item"><i style="background:#f59e0b;"></i> خالی از سکنه</span>
                        <span class="bldg-legend-item"><i style="background:#94a3b8;"></i> بدون مالک</span>
                    </div>
                    <div class="bldg-hint">💡 برای مشاهده اطلاعات و اقدامات، روی هر واحد بزنید.</div>
                </div>

                <!-- پاپ‌آپ اطلاعات واحد -->
                <?php modal_start('unit-popup', 'اطلاعات واحد', ''); ?>
                    <div id="unit-popup-body"></div>
                <?php modal_end(); ?>

                <script>
                    /* داده واحدها برای پاپ‌آپ + رندر محتوا هنگام کلیک */
                    (function () {
                        var UNITS = <?= json_encode($unit_popup_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                        var IS_MANAGER = <?= $is_manager ? 'true' : 'false' ?>;
                        var BUILDING_ID = <?= (int) $building_id ?>;

                        function esc(s) {
                            return String(s === null || s === undefined ? '' : s)
                                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                        }
                        function faNum(v) {
                            return String(v).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
                        }
                        function fmtMoney(v) {
                            return faNum(Number(v).toLocaleString('en-US'));
                        }

                        function renderUnit(id) {
                            var u = UNITS[id];
                            if (!u) { return; }
                            var rows = [];
                            function addRow(icon, label, value) {
                                if (value === null || value === undefined || value === '') { return; }
                                rows.push('<div class="unit-info-row"><span class="lbl">' + icon + ' ' + esc(label) + '</span><span class="val">' + value + '</span></div>');
                            }

                            var occDot = u.occ_class.replace('occ-', '');
                            addRow('📐', 'مساحت', u.area ? faNum(u.area) + ' متر مربع' : null);
                            addRow('👥', 'ساکنین', u.residents_count > 0 ? faNum(u.residents_count) + ' نفر' : null);

                            var ownerVal = null;
                            if (u.owner_name) {
                                ownerVal = esc(u.owner_name) + (u.owner_phone ? ' — <a href="tel:' + esc(u.owner_phone) + '">' + faNum(u.owner_phone) + '</a>' : '');
                            }
                            addRow('🔑', 'مالک', ownerVal);

                            var tenantVal = null;
                            if (u.tenant_name) {
                                tenantVal = esc(u.tenant_name) + (u.tenant_phone ? ' — <a href="tel:' + esc(u.tenant_phone) + '">' + faNum(u.tenant_phone) + '</a>' : '');
                            }
                            addRow('🧳', 'مستأجر', tenantVal);

                            addRow('🅿️', 'قطعه پارکینگ', u.parking_no ? esc(faNum(u.parking_no)) : null);
                            addRow('📦', 'قطعه انباری', u.storage_no ? esc(faNum(u.storage_no)) : null);
                            addRow('💰', 'شارژ دلخواه', u.custom_charge ? fmtMoney(u.custom_charge) + ' تومان' : null);

                            /* حسابداری واحد: سهم صادرشده، پرداخت تأییدشده و مانده بدهکار/طلبکار */
                            if (u.balance) {
                                addRow('🧾', 'جمع سهم صادرشده', fmtMoney(u.balance.total_share) + ' تومان');
                                addRow('✅', 'پرداخت تأییدشده', fmtMoney(u.balance.total_paid) + ' تومان');
                                var bal = parseFloat(u.balance.balance) || 0;
                                var balLabel = bal < 0
                                    ? fmtMoney(Math.abs(bal)) + ' تومان بدهکار'
                                    : (bal > 0 ? fmtMoney(bal) + ' تومان طلبکار' : 'تسویه شده');
                                rows.push('<div class="unit-info-row"><span class="lbl">⚖️ مانده حساب</span>' +
                                    '<span class="val" style="color:' + (bal < 0 ? '#b91c1c' : (bal > 0 ? '#047857' : '#64748b')) + ';font-weight:800;">' + esc(balLabel) + '</span></div>');
                            }

                            var chips =
                                '<span class="chip chip-gray">' + esc(u.type_icon + ' ' + u.type_label) + '</span>' +
                                '<span class="chip chip-gray">📍 ' + esc(u.floor_label) + '</span>' +
                                (u.block_label ? '<span class="chip chip-gray">🧱 ' + esc(u.block_label) + '</span>' : '') +
                                '<span class="chip ' + (occDot === 'owner' ? 'chip-green' : occDot === 'tenant' ? 'chip-blue' : 'chip-amber') + '">' + esc(u.occ_label) + '</span>';

                            var actions = '';
                            if (IS_MANAGER) {
                                actions =
                                    '<div class="unit-modal-actions">' +
                                    '<a class="btn-chip btn-chip-edit" href="units.php?building_id=' + BUILDING_ID + '&focus=' + id + '">✏️ ویرایش واحد</a>' +
                                    '<form method="POST" action="" style="flex:1;display:flex;" onsubmit="return confirm(\'این واحد حذف شود؟ این عملیات بازگشت‌پذیر نیست.\');">' +
                                    '<input type="hidden" name="form_action" value="delete_unit">' +
                                    '<input type="hidden" name="unit_id" value="' + id + '">' +
                                    '<button type="submit" class="btn-chip btn-chip-danger" style="flex:1;justify-content:center;">🗑️ حذف واحد</button>' +
                                    '</form>' +
                                    '</div>';
                            }

                            document.getElementById('unit-popup-body').innerHTML =
                                '<div class="unit-modal-head">' +
                                '<div class="unit-modal-badge">' + u.type_icon + '</div>' +
                                '<div><div class="unit-modal-title">واحد ' + esc(faNum(u.number)) + '</div>' +
                                '<div class="unit-modal-sub">' + esc(u.occ_label) + (u.occupant_name ? ' — ' + esc(u.occupant_name) : '') + '</div></div>' +
                                '</div>' +
                                '<div class="unit-info-chips">' + chips + '</div>' +
                                '<div class="unit-info-rows">' + rows.join('') + '</div>' +
                                actions;
                        }

                        document.querySelectorAll('[data-unit-open]').forEach(function (cell) {
                            function open() {
                                var id = cell.getAttribute('data-unit-open');
                                renderUnit(id);
                                if (window.openModal) { window.openModal('unit-popup'); }
                            }
                            cell.addEventListener('click', open);
                            cell.addEventListener('keydown', function (e) {
                                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
                            });
                        });

                        /* بازشدن خودکار مودال واحد مشخص‌شده از طریق لینک (?unit=ID) */
                        var params = new URLSearchParams(window.location.search);
                        var focusUnit = params.get('unit');
                        if (focusUnit && UNITS[focusUnit]) {
                            renderUnit(focusUnit);
                            if (window.openModal) { window.openModal('unit-popup'); }
                        }
                    })();
                </script>
            <?php endif; ?>
        </section>

        <!-- ماژول‌ها -->
        <section class="quick-access-section">
            <div class="section-header-row">
                <span class="section-title">سرویس‌ها و ماژول‌ها</span>
                <a href="reviews.php?building_id=<?= $building_id ?>" class="widget-view-all-link">نظرات</a>
            </div>
            <div class="tile-grid">
                <a href="bookings.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #eff6ff; color: #3b82f6;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <line x1="16" y1="2" x2="16" y2="6" />
                            <line x1="8" y1="2" x2="8" y2="6" />
                            <line x1="3" y1="10" x2="21" y2="10" />
                        </svg>
                    </div>
                    <span class="tile-label">رزرو</span>
                </a>
                <a href="votes.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #f5f3ff; color: #8b5cf6;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0 1 12 2.944a11.955 11.955 0 0 1-8.618 3.04A12.02 12.02 0 0 0 3 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <span class="tile-label">رأی‌گیری</span>
                </a>
                <a href="visitors.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #f0fdfa; color: #14b8a6;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                        </svg>
                    </div>
                    <span class="tile-label">مهمان‌ها</span>
                </a>
                <a href="documents.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #f0f9ff; color: #0ea5e9;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z" />
                        </svg>
                    </div>
                    <span class="tile-label">اسناد</span>
                </a>
                <a href="consumption.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #f7fee7; color: #84cc16;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <span class="tile-label">مصرف</span>
                </a>
                <a href="emergency_contacts.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #fef2f2; color: #ef4444;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 5a2 2 0 0 1 2-2h3.28a1 1 0 0 1 .948.684l1.498 4.493a1 1 0 0 1-.502 1.21l-2.257 1.13a11.042 11.042 0 0 0 5.516 5.516l1.13-2.257a1 1 0 0 1 1.21-.502l4.493 1.498a1 1 0 0 1 .684.949V19a2 2 0 0 1-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                    <span class="tile-label">اضطراری</span>
                </a>
                <a href="meetings.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #eef2ff; color: #6366f1;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                        </svg>
                    </div>
                    <span class="tile-label">جلسات</span>
                </a>
                <a href="reviews.php?building_id=<?= $building_id ?>" class="tile-card">
                    <div class="tile-icon" style="background: #fdf2f8; color: #ec4899;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 0 0 .95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 0 0-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 0 0-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 0 0-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 0 0 .951-.69l1.519-4.674z" />
                        </svg>
                    </div>
                    <span class="tile-label">نظرات</span>
                </a>
            </div>
        </section>

        <div class="card p-4 mt-3">
                <div class="flex flex-wrap gap-2 text-[11px]">
                    <?php if (!empty($building['total_units'])): ?>
                        <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700"><?= fa_digits($building['total_units']) ?> واحد</span>
                    <?php endif; ?>
                    <?php if (!empty($building['total_floors'])): ?>
                        <span class="px-2.5 py-1 rounded-full bg-amber-50 text-amber-700"><?= fa_digits($building['total_floors']) ?> طبقه</span>
                    <?php endif; ?>
                    <?php if (!empty($building['has_blocks'])): ?>
                        <span class="px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700"><?= fa_digits(count($blocks)) ?> بلوک</span>
                    <?php endif; ?>
                    <?php if (!empty($building['parking_spots'])): ?>
                        <span class="px-2.5 py-1 rounded-full bg-sky-50 text-sky-700"><?= fa_digits($building['parking_spots']) ?> ظرفیت پارکینگ</span>
                    <?php endif; ?>
                    <?php if (!empty($building['monthly_charge_enabled']) && !empty($building['monthly_charge'])): ?>
                        <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700">شارژ ماهیانه: <?= fa_number($building['monthly_charge']) ?> تومان</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- اطلاعات اجمالی -->
        <section class="quick-access-section">
            <div class="section-header-row">
                <span class="section-title">اطلاعات اجمالی</span>
            </div>
            <a href="members.php?building_id=<?= $building_id ?>" class="info-row">
                <div class="info-row-right">
                    <div class="info-row-icon" style="background: #eef2ff; color: #6366f1;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                            <circle cx="9" cy="7" r="4" />
                        </svg>
                    </div>
                    <span class="info-row-label">اعضای ساختمان</span>
                </div>
                <span class="info-row-value"><?= fa_digits(count($members)) ?> نفر</span>
            </a>
            <a href="units.php?building_id=<?= $building_id ?>" class="info-row">
                <div class="info-row-right">
                    <div class="info-row-icon" style="background: #fefce8; color: #eab308;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 12l2-2m0 0 7-7 7 7M5 10v10a1 1 0 0 0 1 1h3m10-11l2 2m-2-2v10a1 1 0 0 1-1 1h-3m-6 0a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1m-6 0h6" />
                        </svg>
                    </div>
                    <span class="info-row-label">واحدهای ساختمان</span>
                </div>
                <span class="info-row-value"><?= fa_digits(count($units)) ?> واحد</span>
            </a>
            <a href="costs.php?building_id=<?= $building_id ?>" class="info-row">
                <div class="info-row-right">
                    <div class="info-row-icon" style="background: #ecfdf5; color: #10b981;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="4" width="20" height="16" rx="2" />
                            <line x1="12" y1="20" x2="12" y2="4" />
                        </svg>
                    </div>
                    <span class="info-row-label">وضعیت مالی</span>
                </div>
                <span class="info-row-value"><?= fa_digits((int) round((float) ($financial['collection_percentage'] ?? 0))) ?>٪ وصول</span>
            </a>
            <a href="tickets.php?building_id=<?= $building_id ?>" class="info-row">
                <div class="info-row-right">
                    <div class="info-row-icon" style="background: #eff6ff; color: #3b82f6;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 0 0-2 2v3a2 2 0 1 1 0 4v3a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3a2 2 0 1 1 0-4V7a2 2 0 0 0-2-2H5z" />
                        </svg>
                    </div>
                    <span class="info-row-label">تیکت‌های باز</span>
                </div>
                <span class="info-row-value"><?= fa_digits($open_tickets) ?> تیکت</span>
            </a>
        </section>

<?php require_once 'includes/dash_tail.php'; ?>
