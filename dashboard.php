<?php
require_once 'includes/api_helper.php';

// بررسی لاگین کاربر
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

// دریافت اطلاعات کاربر (نام واقعی از API)
$me_response = callAPI('GET', '/auth/me');
$userName = $me_response['data']['name'] ?? ($_SESSION['user_name'] ?? 'کاربر');

// دریافت لیست ساختمان‌ها
$buildings_response = callAPI('GET', '/buildings');
$buildings_list = [];
if (isset($buildings_response['success']) && $buildings_response['success'] === true && !empty($buildings_response['data'])) {
    $buildings_list = $buildings_response['data'];
}

// انتخاب ساختمان: پارامتر building_id، وگرنه آخرین انتخاب، وگرنه اولین ساختمان
$requested_id = (int) ($_GET['building_id'] ?? 0);
$active_building = null;
foreach ($buildings_list as $b) {
    if ($requested_id > 0 && (int) ($b['id'] ?? 0) === $requested_id) {
        $active_building = $b;
        break;
    }
}
if (!$active_building && !empty($buildings_list)) {
    $sess_id = (int) ($_SESSION['active_building_id'] ?? 0);
    foreach ($buildings_list as $b) {
        if ($sess_id > 0 && (int) ($b['id'] ?? 0) === $sess_id) {
            $active_building = $b;
            break;
        }
    }
}
if (!$active_building && !empty($buildings_list)) {
    $active_building = $buildings_list[0];
}
if (!$active_building) {
    // ساختمانی وجود ندارد → لیست ساختمان‌ها
    header("Location: index.php");
    exit;
}
$_SESSION['active_building_id'] = $active_building['id'];

$building_id = (int) ($active_building['id'] ?? 0);
// نقش کاربر جاری در این ساختمان (مدیر / عضو)
$my_role = $active_building['my_role'] ?? 'resident';
$is_manager = ($my_role === 'manager');

// ---- حذف واحد از پاپ‌آپ نمای گرافیکی (فقط مدیر) — سپس ریدایرکت برای جلوگیری از ثبت مجدد ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'delete_unit') {
    $unit_id = (int) ($_POST['unit_id'] ?? 0);
    if (!$is_manager) {
        header("Location: dashboard.php?building_id={$building_id}&flash=forbidden");
        exit;
    }
    $del_response = callAPI('DELETE', '/units/' . $unit_id);
    header("Location: dashboard.php?building_id={$building_id}&flash=" . (!empty($del_response['success']) ? 'unit_deleted' : 'unit_delete_failed'));
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

// ---------- داده‌های واقعی داشبورد (بر اساس ساختمان فعال) ----------

// ---------- دادهٔ تجمیعی داشبورد — یک درخواست به‌جای ~۱۰ درخواست مجزا ----------
$members = [];
$units = [];
$blocks = [];
$floors = [];
$unit_balances_by_unit = [];
$all_payments = [];
$all_costs = [];
$announcements = [];
$maintenance_requests = [];
$financial = [
    'total_costs' => 0,
    'total_collected' => 0,
    'total_remaining' => 0,
    'collection_percentage' => 0,
];
if ($building_id > 0) {
    $dash_response = callAPI('GET', '/buildings/' . $building_id . '/dashboard');
    if (!empty($dash_response['success']) && is_array($dash_response['data'] ?? null)) {
        $d = $dash_response['data'];
        $members = is_array($d['members'] ?? null) ? $d['members'] : [];
        $units = is_array($d['units'] ?? null) ? $d['units'] : [];
        $blocks = is_array($d['blocks'] ?? null) ? $d['blocks'] : [];
        $floors = is_array($d['floors'] ?? null) ? $d['floors'] : [];
        foreach ($d['unit_balances'] ?? [] as $bal) {
            $unit_balances_by_unit[(int) ($bal['unit_id'] ?? 0)] = $bal;
        }
        $all_payments = is_array($d['payments'] ?? null) ? $d['payments'] : [];
        $all_costs = is_array($d['costs'] ?? null) ? $d['costs'] : [];
        $announcements = is_array($d['announcements'] ?? null) ? $d['announcements'] : [];
        $maintenance_requests = is_array($d['maintenance'] ?? null) ? $d['maintenance'] : [];
        $financial = array_merge($financial, is_array($d['financial_summary'] ?? null) ? $d['financial_summary'] : []);
    }
}
$members_count = count($members);

// ---------- «واحد من»: واحدِ کاربر جاری، مانده و پرداخت‌نشده‌ها ----------
$my_user_id = (int) ($_SESSION['user_id'] ?? 0);
$my_unit = null;
foreach ($units as $u) {
    if ((int) ($u['owner_user_id'] ?? 0) === $my_user_id || (int) ($u['tenant_user_id'] ?? 0) === $my_user_id) {
        $my_unit = $u;
        break;
    }
}
$my_balance = $my_unit ? ($unit_balances_by_unit[(int) ($my_unit['id'] ?? 0)] ?? null) : null;

// جمع پرداخت‌نشده‌های من + نزدیک‌ترین مهلت پرداخت
$my_unpaid_total = 0.0;
$my_unpaid_count = 0;
$my_next_due = null;
if ($my_user_id > 0 && $building_id > 0) {
    $my_pending_cost_ids = [];
    foreach ($all_payments as $p) {
        if ((int) ($p['user_id'] ?? 0) !== $my_user_id || ($p['status'] ?? '') === 'confirmed') {
            continue;
        }
        $my_unpaid_total += (float) ($p['share_amount'] ?? 0);
        $my_unpaid_count++;
        $my_pending_cost_ids[] = (int) ($p['cost_id'] ?? 0);
    }
    if ($my_pending_cost_ids) {
        foreach ($all_costs as $c) {
            if (!in_array((int) ($c['id'] ?? 0), $my_pending_cost_ids, true)) {
                continue;
            }
            $due = (string) ($c['due_date'] ?? '');
            if ($due !== '' && ($my_next_due === null || $due < $my_next_due)) {
                $my_next_due = $due;
            }
        }
    }
}

// درخواست‌های فعال (در انتظار / در حال بررسی)
$active_requests_count = 0;
foreach ($maintenance_requests as $mr) {
    if (in_array($mr['status'] ?? '', ['pending', 'in_progress'], true)) {
        $active_requests_count++;
    }
}

// وضعیت نمایشی درخواست‌ها: برچسب و کلاس بج
$request_status_map = [
    'pending' => ['label' => 'جدید', 'class' => 'new'],
    'in_progress' => ['label' => 'در حال بررسی', 'class' => 'warning'],
    'resolved' => ['label' => 'تکمیل شد', 'class' => 'done'],
    'closed' => ['label' => 'بسته شد', 'class' => 'done'],
    'rejected' => ['label' => 'رد شده', 'class' => 'done'],
];

$page_title     = 'داشبورد';
$header_sub     = 'به خانه‌تان خوش آمدید 👋';
$header_variant = 'home';
$nav_active     = 'dashboard';
$unread_nav     = $unread_notifs;
require_once 'includes/header.php';
?>

        <!-- کارت بزرگ معرفی ساختمان -->
        <section class="building-hero-card">

            <div class="building-details-wrapper">
                <div class="building-header-row">
                    <div class="building-name-container">
                        <h2 onclick="window.location.href='index.php'" style="cursor:pointer;" title="تغییر ساختمان">
                            <?= htmlspecialchars($active_building['name'] ?? 'ساختمانی یافت نشد') ?>
                            <svg width="10" height="6" viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m1 1 4 4 4-4" />
                            </svg>
                        </h2>
                        <p><?= htmlspecialchars($userName) ?> • <?= htmlspecialchars(member_role_label($my_role)) ?></p>
                    </div>
                    <div class="building-logo-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="<?= htmlspecialchars($active_building['theme_color'] ?? 'var(--gold-primary)') ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="10" width="4" height="11" rx="1" />
                            <rect x="8" y="2" width="4" height="19" rx="1" />
                            <rect x="14" y="8" width="4" height="13" rx="1" />
                            <rect x="20" y="13" width="2" height="8" rx="1" />
                        </svg>
                    </div>
                </div>

                <div class="building-meta-specs" style="font-size: 10px; opacity: 0.8; margin-top: 4px;">
                    <?= htmlspecialchars($active_building['address'] ?? 'لطفا یک ساختمان ثبت کنید') ?>
                </div>

                <?php if ($active_building): ?>
                    <?php if ($is_manager): ?>
                    <button class="btn-view-profile" onclick="window.location.href='costs.php?building_id=<?= (int) $active_building['id'] ?>'">
                        <span>مدیریت مالی و شارژ</span>
                        <svg width="6" height="10" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 9 1 5l4-4" />
                        </svg>
                    </button>
                    <div style="display:flex; gap:8px; margin-top:8px;">
                        <button class="btn-view-profile" style="flex:1;" onclick="window.location.href='building_edit.php?id=<?= (int) $active_building['id'] ?>'">
                            <span>ویرایش اطلاعات ساختمان</span>
                        </button>
                    </div>
                    <?php else: ?>
                    <button class="btn-view-profile" onclick="window.location.href='costs.php?building_id=<?= $active_building['id'] ?>'">
                        <span>مشاهده و پرداخت شارژ</span>
                        <svg width="6" height="10" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 9 1 5l4-4" />
                        </svg>
                    </button>
                    <?php endif; ?>
                <?php else: ?>
                    <button class="btn-view-profile" onclick="window.location.href='building_add.php'">
                        <span>ثبت ساختمان جدید</span>
                        <svg width="6" height="10" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 9 1 5l4-4" />
                        </svg>
                    </button>
                <?php endif; ?>
            </div>
            <div class="building-image-wrapper">
                <img src="<?= htmlspecialchars(building_cover($active_building ?? [])) ?>" alt="<?= htmlspecialchars($active_building['name'] ?? 'ساختمان') ?>">
            </div>
        </section>

        <?php if (!$is_manager): ?>
        <div class="app-alert app-alert-info" style="margin:12px 0 0;font-size:12px;">
            شما با نقش «<?= htmlspecialchars(member_role_label($my_role)) ?>» وارد شده‌اید؛ دسترسی‌های مدیریتی برای شما نمایش داده نمی‌شود.
        </div>
        <?php endif; ?>

        <?php if ($my_unit): ?>
        <!-- کارت «واحد من»: وضعیت مالی و مشخصات واحد کاربر جاری -->
        <section class="my-unit-card card" style="margin-top:12px; padding:16px;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:20px;">🏠</span>
                    <div>
                        <strong style="font-size:13px;">واحد من — <?= fa_digits(htmlspecialchars((string) ($my_unit['unit_number'] ?? ''))) ?></strong>
                        <div style="font-size:10px; color:var(--text-gray);">
                            <?= htmlspecialchars($my_unit['type'] === 'commercial' ? 'تجاری' : ($my_unit['type'] === 'office' ? 'اداری' : 'مسکونی')) ?>
                            <?php if (!empty($my_unit['residents_count'])): ?> · <?= fa_digits($my_unit['residents_count']) ?> ساکن<?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($my_balance): ?>
                    <?php $bal = (float) ($my_balance['balance'] ?? 0); ?>
                    <span style="font-size:11px; font-weight:800; padding:5px 10px; border-radius:999px;
                        background: <?= $bal < 0 ? '#fee2e2' : ($bal > 0 ? '#d1fae5' : '#e2e8f0') ?>;
                        color: <?= $bal < 0 ? '#991b1b' : ($bal > 0 ? '#065f46' : '#475569') ?>;">
                        <?= $bal < 0 ? fa_number(abs($bal)) . ' تومان بدهکار' : ($bal > 0 ? fa_number($bal) . ' تومان طلبکار' : 'تسویه شده') ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($my_unpaid_count > 0): ?>
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; background:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:10px 12px;">
                <div style="font-size:12px; color:#9a3412;">
                    💳 <strong><?= fa_number($my_unpaid_total) ?> تومان</strong>
                    پرداخت‌نشده (<?= fa_digits($my_unpaid_count) ?> مورد)
                    <?php if ($my_next_due): ?>
                        <div style="font-size:10px; margin-top:3px;">⏰ مهلت پرداخت: <?= fa_date($my_next_due) ?></div>
                    <?php endif; ?>
                </div>
                <button class="btn-view-profile" style="white-space:nowrap;" onclick="window.location.href='costs.php?building_id=<?= (int) $building_id ?>'">
                    <span>پرداخت</span>
                </button>
            </div>
            <?php else: ?>
            <div style="font-size:12px; color:#047857; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:12px; padding:10px 12px;">
                ✅ پرداخت باز یا معوقی ندارید.
                <?php if ($my_next_due): ?>
                    <div style="font-size:10px; margin-top:3px; color:var(--text-gray);">⏰ مهلت بعدی: <?= fa_date($my_next_due) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($my_unit['parking_no']) || !empty($my_unit['storage_no'])): ?>
            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:10px;">
                <?php if (!empty($my_unit['parking_no'])): ?>
                    <span class="chip chip-gray">🅿️ پارکینگ: <?= fa_digits(htmlspecialchars((string) $my_unit['parking_no'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($my_unit['storage_no'])): ?>
                    <span class="chip chip-gray">📦 انباری: <?= fa_digits(htmlspecialchars((string) $my_unit['storage_no'])) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- کارت سفید آمار ۴ ستونه -->
        <?php if ($is_manager): ?>
        <section class="statistics-grid-card">
            <!-- ستون ۱ (راست) -->
            <div class="stat-column">
                <div class="stat-icon-box purple">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="4" width="20" height="16" rx="2" />
                        <line x1="12" y1="20" x2="12" y2="4" />
                    </svg>
                </div>
                <div class="stat-numeric-value"><?= fa_number($financial['total_remaining']) ?></div>
                <div class="stat-text-label">مانده حساب ساختمان</div>
                <div class="stat-growth-rate link" onclick="window.location.href='costs.php?building_id=<?= (int) $building_id ?>'" style="cursor:pointer;">
                    مشاهده جزئیات
                    <svg width="4" height="7" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 9 1 5l4-4" />
                    </svg>
                </div>
            </div>

            <!-- ستون ۲ -->
            <div class="stat-column">
                <div class="stat-icon-box orange">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    </svg>
                </div>
                <div class="stat-numeric-value"><?= fa_digits($unread_notifs) ?></div>
                <div class="stat-text-label">اعلان‌های جدید</div>
                <div class="stat-growth-rate up">
                    <span>خوانده نشده</span>
                    <span style="color:var(--text-gray); font-size:7px; font-weight:normal;">امروز</span>
                </div>
            </div>

            <!-- ستون ۳ -->
            <div class="stat-column">
                <div class="stat-icon-box blue">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                        <line x1="16" y1="13" x2="8" y2="13" />
                        <line x1="16" y1="17" x2="8" y2="17" />
                        <polyline points="10 9 9 9 8 9" />
                    </svg>
                </div>
                <div class="stat-numeric-value"><?= fa_digits($active_requests_count) ?></div>
                <div class="stat-text-label">درخواست فعال</div>
                <div class="stat-growth-rate up">
                    <span>در جریان</span>
                    <span style="color:var(--text-gray); font-size:7px; font-weight:normal;">تعمیرات</span>
                </div>
            </div>

            <!-- ستون ۴ (چپ) -->
            <div class="stat-column">
                <div class="stat-icon-box teal">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                </div>
                <div class="stat-numeric-value"><?= fa_digits($members_count) ?></div>
                <div class="stat-text-label">اعضای ساختمان</div>
                <div class="stat-growth-rate up">
                    <span>عضو فعال</span>
                    <span style="color:var(--text-gray); font-size:7px; font-weight:normal;">ساختمان</span>
                </div>
            </div>
        </section>

        <?php endif; ?>

        <!-- بخش دسترسی سریع -->
        <section class="quick-access-section">
            <div class="section-header-row">
                <h2 class="section-title">دسترسی سریع</h2>
                <?php if ($is_manager): ?>
                <a class="edit-action-btn" href="building_edit.php?id=<?= (int) $building_id ?>" onclick="event.stopPropagation();">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 20h9" />
                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                    </svg>
                    ویرایش
                </a>
                <?php endif; ?>
            </div>

            <div class="quick-buttons-row">
                <?php if ($is_manager): ?>
                <!-- آیتم ۱ -->
                <div class="quick-btn-item" onclick="window.location.href='reports.php?building_id=<?= (int) $building_id ?>'" style="cursor:pointer;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <line x1="9" y1="9" x2="15" y2="9" />
                        <line x1="9" y1="13" x2="15" y2="13" />
                        <line x1="9" y1="17" x2="13" y2="17" />
                    </svg>
                    <span>گزارش‌ها</span>
                </div>
                <!-- آیتم ۲ -->
                <div class="quick-btn-item" onclick="window.location.href='maintenance.php?building_id=<?= (int) $building_id ?>'" style="cursor:pointer;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
                    </svg>
                    <span>تاسیسات</span>
                </div>
                <!-- آیتم ۳ -->
                <div class="quick-btn-item" onclick="window.location.href='visitors.php?building_id=<?= (int) $building_id ?>'" style="cursor:pointer;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#a855f7" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                    <span>بازدیدها</span>
                </div>
                <?php endif; ?>
                <?php if ($is_manager): ?>
                <!-- آیتم ۴ -->
                <div class="quick-btn-item" onclick="window.location.href='documents.php?building_id=<?= (int) $building_id ?>'" style="cursor:pointer;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2">
                        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                    </svg>
                    <span>مدارک ساختمان</span>
                </div>
                <!-- آیتم ۴ب: لاگ ممیزی اقدامات -->
                <div class="quick-btn-item" onclick="window.location.href='audit_logs.php?building_id=<?= (int) $building_id ?>'" style="cursor:pointer;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2">
                        <path d="M12 20h9" />
                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" />
                    </svg>
                    <span>لاگ اقدامات</span>
                </div>
                <?php endif; ?>
                <!-- آیتم ۵ -->
                <div class="quick-btn-item" onclick="window.location.href='members.php?building_id=<?= (int) $building_id ?>'" style="cursor:pointer;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                    </svg>
                    <span>اعضا</span>
                </div>
            </div>
        </section>

        <!-- بخش ستون‌های دوتایی -->
        <section class="split-widgets-container">

            <!-- ستون راست: اعلان‌های اخیر (روشن) -->
            <div class="widget-column-box">
                <div class="widget-box-header">
                    <h3>اعلان‌های اخیر</h3>
                    <span class="widget-view-all-link gold" onclick="window.location.href='announcements.php?building_id=<?= (int) $building_id ?>'" style="cursor:pointer;">مشاهده همه</span>
                </div>

                <div class="light-announcements-card">
                    <?php if (empty($announcements)): ?>
                        <!-- حالت خالی -->
                        <div class="announcement-list-item">
                            <div class="announcement-icon-wrapper blue">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" />
                                </svg>
                            </div>
                            <div class="announcement-text-content">
                                <div class="announcement-top-row">
                                    <span class="announcement-item-title">اطلاعیه‌ای ثبت نشده</span>
                                    <span class="announcement-item-time"></span>
                                </div>
                                <p class="announcement-item-description">هنوز اطلاعیه‌ای برای این ساختمان ثبت نشده است.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php
                        $announcement_icons = [
                            ['color' => 'blue', 'type' => 'bell'],
                            ['color' => 'red', 'type' => 'calendar'],
                            ['color' => 'green', 'type' => 'wallet'],
                        ];
                        $i = 0;
                        foreach (array_slice($announcements, 0, 3) as $announcement):
                            $icon = $announcement_icons[$i % 3];
                            $i++;
                        ?>
                            <div class="announcement-list-item">
                                <div class="announcement-icon-wrapper <?= $icon['color'] ?>">
                                    <?php if ($icon['type'] === 'bell'): ?>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" />
                                        </svg>
                                    <?php elseif ($icon['type'] === 'calendar'): ?>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <rect x="3" y="4" width="18" height="18" rx="2" />
                                            <line x1="16" y1="2" x2="16" y2="6" />
                                            <line x1="8" y1="2" x2="8" y2="6" />
                                        </svg>
                                    <?php else: ?>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <rect x="2" y="5" width="20" height="14" rx="2" />
                                            <line x1="12" y1="15" x2="12" y2="15" />
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="announcement-text-content">
                                    <div class="announcement-top-row">
                                        <span class="announcement-item-title"><?= htmlspecialchars($announcement['title'] ?? '') ?></span>
                                        <span class="announcement-item-time"><?= htmlspecialchars(fa_time_ago($announcement['created_at'] ?? '')) ?></span>
                                    </div>
                                    <p class="announcement-item-description"><?= htmlspecialchars($announcement['content'] ?? '') ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <button class="btn-show-all-announcements" onclick="window.location.href='announcements.php?building_id=<?= (int) $building_id ?>'">
                        مشاهده همه اعلان‌ها
                        <svg width="5" height="8" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 9 1 5l4-4" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- ستون چپ: درخواست‌های اخیر (تیره) -->
            <div class="widget-column-box">
                <div class="widget-box-header dark">
                    <h3 style="color: white;">درخواست‌های اخیر</h3>
                    <span class="widget-view-all-link" onclick="window.location.href='maintenance.php?building_id=<?= (int) $building_id ?>'" style="cursor:pointer;">مشاهده همه</span>
                </div>

                <div class="dark-requests-card">
                    <?php if (empty($maintenance_requests)): ?>
                        <!-- حالت خالی -->
                        <div class="request-list-item">
                            <div class="request-item-right-info">
                                <div class="request-icon-container">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue-info)" stroke-width="2">
                                        <path d="m3 16 4 4 4-4M7 20V4M21 8l-4-4-4 4M17 4v16" />
                                    </svg>
                                </div>
                                <div class="request-text-meta">
                                    <h4>درخواستی ثبت نشده</h4>
                                    <p>هنوز درخواست تعمیراتی وجود ندارد</p>
                                </div>
                            </div>
                            <span class="request-badge-status new">جدید</span>
                        </div>
                    <?php else: ?>
                        <?php
                        $request_icons = [
                            'wrench' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue-info)" stroke-width="2"><path d="m3 16 4 4 4-4M7 20V4M21 8l-4-4-4 4M17 4v16" /></svg>',
                            'shield' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue-info)" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" /></svg>',
                            'bulb' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gold-primary)" stroke-width="2"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A5 5 0 0 0 8 8c0 1 .3 2.2 1 3M9 18h6M10 22h4" /></svg>',
                        ];
                        $request_icon_keys = ['wrench', 'shield', 'bulb'];
                        $i = 0;
                        foreach (array_slice($maintenance_requests, 0, 3) as $mr):
                            $icon_key = $request_icon_keys[$i % 3];
                            $i++;
                            $status = $request_status_map[$mr['status'] ?? ''] ?? ['label' => 'در انتظار', 'class' => 'warning'];
                        ?>
                            <div class="request-list-item">
                                <div class="request-item-right-info">
                                    <div class="request-icon-container">
                                        <?= $request_icons[$icon_key] ?>
                                    </div>
                                    <div class="request-text-meta">
                                        <h4><?= htmlspecialchars($mr['title'] ?? '') ?></h4>
                                        <p><?= htmlspecialchars(fa_time_ago($mr['created_at'] ?? '')) ?></p>
                                    </div>
                                </div>
                                <span class="request-badge-status <?= $status['class'] ?>"><?= htmlspecialchars($status['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <button class="btn-add-new-request" onclick="window.location.href='maintenance.php?building_id=<?= (int) $building_id ?>'">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="12" y1="5" x2="12" y2="19" />
                            <line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        ثبت درخواست جدید
                    </button>
                </div>
            </div>

        </section>

        <?php if ($is_manager): ?>
        <!-- کارت وضعیت مالی ساختمان -->
        <section class="financial-overview-card">
            <div class="financial-card-header" onclick="window.location.href='costs.php?building_id=<?= (int) $building_id ?>'" style="cursor:pointer;">
                <h3>وضعیت مالی ساختمان</h3>
                <span>مشاهده جزئیات</span>
            </div>

            <div class="financial-data-row">
                <!-- نمودار دایره‌ای سمت راست -->
                <div class="gauge-chart-container">
                    <svg width="72" height="72" viewBox="0 0 36 36">
                        <!-- دایره پس‌زمینه خاکستری تیره -->
                        <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="2.8" />
                        <!-- دایره رنگی طلایی مقدار درصد وصولی واقعی -->
                        <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="url(#goldGradient)" stroke-width="2.8" stroke-dasharray="<?= max(0, min(100, (float) ($financial['collection_percentage'] ?? 0))) ?>, 100" stroke-linecap="round" />
                        <defs>
                            <linearGradient id="goldGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#f59e0b" />
                                <stop offset="100%" stop-color="#b45309" />
                            </linearGradient>
                        </defs>
                    </svg>
                    <div class="gauge-inner-text">
                        <span class="percentage"><?= fa_digits((int) round((float) ($financial['collection_percentage'] ?? 0))) ?>٪</span>
                        <span class="label">از کل هزینه‌ها</span>
                    </div>
                </div>

                <!-- آمارهای میانی -->
                <div class="financial-stats-middle">
                    <div class="financial-stat-block">
                        <p>مجموع درآمدها</p>
                        <h4><?= fa_number($financial['total_collected'] ?? 0) ?></h4>
                        <div class="financial-trend up">
                            <span>واریز تأیید شده</span>
                            <span style="color:var(--text-muted-white); font-weight:normal;"><?= fa_digits($financial['confirmed_count'] ?? 0) ?> پرداخت</span>
                        </div>
                    </div>
                    <div class="financial-stat-block">
                        <p>مجموع هزینه‌ها</p>
                        <h4><?= fa_number($financial['total_costs'] ?? 0) ?></h4>
                        <div class="financial-trend down">
                            <span>کل هزینه‌ها</span>
                            <span style="color:var(--text-muted-white); font-weight:normal;"><?= fa_digits($financial['costs_count'] ?? 0) ?> هزینه</span>
                        </div>
                    </div>
                </div>

                <!-- آیکون کیف پول سمت چپ -->
                <div class="financial-wallet-icon-box">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--gold-primary)" stroke-width="2">
                        <rect x="2" y="5" width="20" height="14" rx="2" />
                        <path d="M22 10h-6a2 2 0 0 0 0 4h6" />
                    </svg>
                </div>
            </div>
        </section>

        <?php endif; ?>

        <?php
        // بخش‌های ساختمان (ساختار مجتمع، نمای واحدها، ماژول‌ها و اطلاعات اجمالی)
        // — صفحهٔ جداگانهٔ «پروفایل ساختمان» حذف و در همین داشبورد یکپارچه شده است.
        $bv_members = $members;
        $bv_units = $units;
        $bv_blocks = $blocks;
        $bv_floors = $floors;
        $bv_financial = $financial;
        $bv_balances = $unit_balances_by_unit;
        require 'includes/_building_profile_sections.php';
        ?>

<?php require_once 'includes/footer.php'; ?>
