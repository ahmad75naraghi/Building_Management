<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

$building_name = '';
$financial = [];
$tickets = [];
$maintenance = [];
$bookings = [];
$visitors = [];
$votes = [];
$reviews = [];

if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $summary_response = callAPI('GET', '/costs/summary', ['building_id' => $building_id]);
    if (isset($summary_response['success']) && $summary_response['success'] === true) {
        $financial = $summary_response['data'] ?? [];
    }
    $t = callAPI('GET', '/tickets', ['building_id' => $building_id]);
    if (isset($t['success']) && $t['success'] === true) $tickets = $t['data'] ?? [];
    $m = callAPI('GET', '/maintenance', ['building_id' => $building_id]);
    if (isset($m['success']) && $m['success'] === true) $maintenance = $m['data'] ?? [];
    $b = callAPI('GET', '/bookings', ['building_id' => $building_id]);
    if (isset($b['success']) && $b['success'] === true) $bookings = $b['data'] ?? [];
    $v = callAPI('GET', '/visitors', ['building_id' => $building_id]);
    if (isset($v['success']) && $v['success'] === true) $visitors = $v['data'] ?? [];
    $vo = callAPI('GET', '/votes', ['building_id' => $building_id]);
    if (isset($vo['success']) && $vo['success'] === true) $votes = $vo['data'] ?? [];
    $r = callAPI('GET', '/reviews', ['building_id' => $building_id]);
    if (isset($r['success']) && $r['success'] === true) $reviews = $r['data'] ?? [];
}

// محاسبات آماری
$open_tickets = 0;
foreach ($tickets as $t) {
    if (!in_array($t['status'] ?? '', ['resolved', 'closed', 'rejected'], true)) $open_tickets++;
}
$pending_maintenance = 0;
foreach ($maintenance as $m) {
    if (($m['status'] ?? '') !== 'resolved' && ($m['status'] ?? '') !== 'closed') $pending_maintenance++;
}
$active_visitors = 0;
foreach ($visitors as $v) {
    if (($v['status'] ?? '') !== 'exited') $active_visitors++;
}
$active_votes = 0;
foreach ($votes as $v) {
    if (($v['status'] ?? '') === 'active') $active_votes++;
}

$avg_rating = 0.0;
if (!empty($reviews)) {
    $sum = 0;
    foreach ($reviews as $r) $sum += (int) ($r['rating'] ?? 0);
    $avg_rating = round($sum / count($reviews), 1);
}

$collection_pct = (float) ($financial['collection_percentage'] ?? 0);

// ---------- خروجی CSV ----------
// با ?csv=1 گزارش کامل به‌صورت فایل CSV (سازگار با اکسل، با BOM یوتی‌اف-۸) دانلود می‌شود.
if (isset($_GET['csv']) && $building_id > 0) {
    $filename = 'building-report-' . $building_id . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'wb');
    // BOM برای تشخیص درست یونیکد در اکسل
    fwrite($out, "\xEF\xBB\xBF");

    $row = static fn(array $cells) => fputcsv($out, $cells);
    $section = static function (string $title) use ($out, $row): void {
        $row([]);
        $row(['=== ' . $title . ' ===']);
    };

    $row(['سامانه مدیریت ساختمان — گزارش عملکرد']);
    $row(['ساختمان', $building_name]);
    $row(['تاریخ گزارش (شمسی)', jdate('Y/m/d')]);
    $row(['تاریخ گزارش (میلادی)', date('Y-m-d')]);

    $section('خلاصه مالی');
    $row(['شاخص', 'مقدار']);
    $row(['مجموع هزینه‌ها (تومان)', $financial['total_costs'] ?? 0]);
    $row(['وصول‌شده (تومان)', $financial['total_collected'] ?? 0]);
    $row(['مانده (تومان)', $financial['total_remaining'] ?? 0]);
    $row(['درصد وصول', ($financial['collection_percentage'] ?? 0) . '%']);
    $row(['تعداد هزینه‌ها', $financial['costs_count'] ?? 0]);

    $section('شاخص‌های کلیدی');
    $row(['شاخص', 'تعداد']);
    $row(['تیکت باز', $open_tickets]);
    $row(['تعمیرات در جریان', $pending_maintenance]);
    $row(['مهمان داخل ساختمان', $active_visitors]);
    $row(['رأی‌گیری فعال', $active_votes]);
    $row(['میانگین رضایت', $avg_rating]);
    $row(['تعداد نظرات', count($reviews)]);
    $row(['تعداد رزرو مشاعات', count($bookings)]);

    if (!empty($tickets)) {
        $section('تیکت‌ها');
        $row(['عنوان', 'دسته', 'اولویت', 'وضعیت', 'ناشناس', 'تاریخ ثبت']);
        foreach ($tickets as $t) {
            $row([
                $t['title'] ?? '',
                $t['category'] ?? '',
                $t['priority'] ?? '',
                $t['status'] ?? '',
                !empty($t['is_anonymous']) ? 'بله' : 'خیر',
                $t['created_at'] ?? '',
            ]);
        }
    }

    if (!empty($maintenance)) {
        $section('درخواست‌های تعمیرات');
        $row(['عنوان', 'وضعیت', 'تاریخ ثبت']);
        foreach ($maintenance as $m) {
            $row([$m['title'] ?? ($m['issue'] ?? ''), $m['status'] ?? '', $m['created_at'] ?? '']);
        }
    }

    if (!empty($bookings)) {
        $section('رزروهای مشاعات');
        $row(['تاریخ رزرو', 'ساعت شروع', 'ساعت پایان', 'وضعیت']);
        foreach ($bookings as $b) {
            $row([$b['booking_date'] ?? '', $b['start_time'] ?? '', $b['end_time'] ?? '', $b['status'] ?? '']);
        }
    }

    if (!empty($visitors)) {
        $section('مهمان‌ها');
        $row(['نام', 'پلاک خودرو', 'تاریخ مراجعه', 'وضعیت']);
        foreach ($visitors as $v) {
            $row([$v['visitor_name'] ?? '', $v['visitor_car_plate'] ?? '', $v['visit_date'] ?? '', $v['status'] ?? '']);
        }
    }

    if (!empty($votes)) {
        $section('رأی‌گیری‌ها');
        $row(['عنوان', 'وضعیت', 'پایان']);
        foreach ($votes as $v) {
            $row([$v['title'] ?? '', $v['status'] ?? '', $v['end_date'] ?? '']);
        }
    }

    if (!empty($reviews)) {
        $section('نظرات و امتیازها');
        $row(['امتیاز', 'نظر', 'تاریخ']);
        foreach ($reviews as $r) {
            $row([$r['rating'] ?? '', $r['comment'] ?? '', $r['created_at'] ?? '']);
        }
    }

    fclose($out);
    exit;
}

// گزارش ریز مانده‌ها به تفکیک ماه + دسترسی خروجی اکسل (فقط مدیر)
$monthly_report = null;
$is_manager = false;
if ($building_id > 0) {
    $ctx = building_role_context($building_id);
    $is_manager = !empty($ctx['is_manager']);
    $mr = callAPI('GET', '/buildings/' . $building_id . '/monthly-report');
    if (!empty($mr['success'])) {
        $monthly_report = $mr['data'] ?? null;
    }
}

$page_title = 'گزارش‌ها';
$header_sub = $building_name ?: 'نمای کلی عملکرد ساختمان';
$back_url = 'index.php';
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <?php if ($building_id > 0): ?>
        <div class="flex flex-wrap justify-end gap-2 mb-3">
            <a href="reports.php?building_id=<?= $building_id ?>&csv=1"
               class="bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors flex items-center gap-2">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" />
                </svg>
                خروجی CSV
            </a>
            <?php if ($is_manager): ?>
                <a href="reports_export.php?building_id=<?= $building_id ?>&type=monthly"
                   class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors flex items-center gap-2">
                    📊 اکسل گزارش ماهانه
                </a>
                <a href="reports_export.php?building_id=<?= $building_id ?>&type=ledger"
                   class="bg-gray-700 hover:bg-gray-800 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition-colors flex items-center gap-2">
                    📒 اکسل لجر واحدها
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- خلاصه مالی -->
    <div class="bg-gradient-to-l from-blue-600 to-blue-500 rounded-2xl p-5 text-white shadow-lg shadow-blue-600/20 mb-4">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold">گزارش مالی</h3>
            <span class="bg-white/20 px-3 py-1 rounded-full text-xs"><?= fa_digits($collection_pct) ?>٪ وصولی</span>
        </div>
        <div class="grid grid-cols-3 gap-3 text-center">
            <div class="bg-white/10 rounded-xl p-3">
                <p class="text-blue-100 text-xs">مجموع هزینه‌ها</p>
                <p class="font-bold text-lg mt-1"><?= fa_number($financial['total_costs'] ?? 0) ?></p>
            </div>
            <div class="bg-white/10 rounded-xl p-3">
                <p class="text-blue-100 text-xs">وصول‌شده</p>
                <p class="font-bold text-lg mt-1"><?= fa_number($financial['total_collected'] ?? 0) ?></p>
            </div>
            <div class="bg-white/10 rounded-xl p-3">
                <p class="text-blue-100 text-xs">مانده</p>
                <p class="font-bold text-lg mt-1"><?= fa_number($financial['total_remaining'] ?? 0) ?></p>
            </div>
        </div>
    </div>

    <!-- ریز مانده‌ها به تفکیک ماه -->
    <?php if ($monthly_report !== null && !empty($monthly_report['months'])): ?>
        <div class="card p-4 mb-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-800 text-sm">📆 ریز مانده‌ها به تفکیک ماه</h3>
                <span class="text-[10px] text-gray-400">
                    مانده فعلی:
                    <b style="color: <?= ($monthly_report['totals']['balance_now'] ?? 0) < 0 ? 'var(--red-danger)' : 'var(--green-success)' ?>">
                        <?= fa_number(abs($monthly_report['totals']['balance_now'] ?? 0)) ?> تومان
                        <?= ($monthly_report['totals']['balance_now'] ?? 0) < 0 ? '(بدهکار)' : (($monthly_report['totals']['balance_now'] ?? 0) > 0 ? '(طلبکار)' : '') ?>
                    </b>
                </span>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:11px;">
                    <thead>
                        <tr style="background:#f1f5f9;color:var(--text-gray);">
                            <th style="padding:8px 6px;text-align:right;">ماه</th>
                            <th style="padding:8px 6px;">صادرشده</th>
                            <th style="padding:8px 6px;">پرداخت‌شده</th>
                            <th style="padding:8px 6px;">مانده پایان ماه</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($monthly_report['months']) as $m): ?>
                            <tr style="border-top:1px solid #f1f5f9;">
                                <td style="padding:7px 6px;text-align:right;" class="font-bold text-gray-700"><?= htmlspecialchars($m['label']) ?></td>
                                <td style="padding:7px 6px;text-align:center;" class="text-gray-600"><?= fa_number($m['charge']) ?></td>
                                <td style="padding:7px 6px;text-align:center;" class="text-gray-600"><?= fa_number($m['paid']) ?></td>
                                <td style="padding:7px 6px;text-align:center;font-weight:700;color:<?= $m['balance_end'] < 0 ? 'var(--red-danger)' : 'var(--green-success)' ?>">
                                    <?= fa_number($m['balance_end']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($is_manager && !empty($monthly_report['units'])): ?>
                <details class="mt-3">
                    <summary class="text-xs font-bold text-gray-600 cursor-pointer">ریز واحدها به تفکیک ماه (<?= fa_digits(count($monthly_report['units'])) ?> واحد)</summary>
                    <?php foreach ($monthly_report['units'] as $unit): ?>
                        <?php if (empty($unit['months'])) continue; ?>
                        <div class="mt-3 p-3 rounded-xl" style="background:var(--surface-2, #f8fafc);">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-bold text-gray-800">واحد <?= htmlspecialchars($unit['unit_number']) ?></p>
                                <p class="text-[10px]" style="color:<?= $unit['balance_now'] < 0 ? 'var(--red-danger)' : 'var(--green-success)' ?>">
                                    مانده فعلی: <?= fa_number($unit['balance_now']) ?> تومان
                                </p>
                            </div>
                            <div class="mt-2 space-y-1">
                                <?php foreach (array_reverse($unit['months']) as $m): ?>
                                    <div class="flex items-center justify-between text-[10px] text-gray-600">
                                        <span><?= htmlspecialchars($m['label']) ?></span>
                                        <span>
                                            صدور <?= fa_number($m['charge']) ?>
                                            · پرداخت <?= fa_number($m['paid']) ?>
                                            · مانده <b><?= fa_number($m['balance_end']) ?></b>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- شاخص‌های کلیدی -->
    <div class="grid grid-cols-2 gap-3">
        <div class="card p-4">
            <div class="text-2xl mb-1">🎫</div>
            <p class="font-bold text-gray-800 text-lg"><?= fa_digits($open_tickets) ?></p>
            <p class="text-xs text-gray-500">تیکت باز</p>
        </div>
        <div class="card p-4">
            <div class="text-2xl mb-1">🔧</div>
            <p class="font-bold text-gray-800 text-lg"><?= fa_digits($pending_maintenance) ?></p>
            <p class="text-xs text-gray-500">تعمیرات در جریان</p>
        </div>
        <div class="card p-4">
            <div class="text-2xl mb-1">🚶</div>
            <p class="font-bold text-gray-800 text-lg"><?= fa_digits($active_visitors) ?></p>
            <p class="text-xs text-gray-500">مهمان داخل ساختمان</p>
        </div>
        <div class="card p-4">
            <div class="text-2xl mb-1">🗳️</div>
            <p class="font-bold text-gray-800 text-lg"><?= fa_digits($active_votes) ?></p>
            <p class="text-xs text-gray-500">رأی‌گیری فعال</p>
        </div>
        <div class="card p-4">
            <div class="text-2xl mb-1">⭐</div>
            <p class="font-bold text-gray-800 text-lg"><?= fa_digits($avg_rating) ?></p>
            <p class="text-xs text-gray-500">میانگین رضایت (<?= fa_digits(count($reviews)) ?> نظر)</p>
        </div>
        <div class="card p-4">
            <div class="text-2xl mb-1">📅</div>
            <p class="font-bold text-gray-800 text-lg"><?= fa_digits(count($bookings)) ?></p>
            <p class="text-xs text-gray-500">رزرو مشاعات</p>
        </div>
    </div>

    <!-- دسترسی سریع -->
    <div class="grid grid-cols-2 gap-3 mt-4">
        <a href="costs.php?building_id=<?= $building_id ?>" class="card p-4 flex items-center gap-3">
            <span class="text-2xl">💰</span>
            <span class="text-sm font-bold text-gray-700">مالی و شارژ</span>
        </a>
        <a href="tickets.php?building_id=<?= $building_id ?>" class="card p-4 flex items-center gap-3">
            <span class="text-2xl">🎫</span>
            <span class="text-sm font-bold text-gray-700">تیکت‌ها</span>
        </a>
        <a href="maintenance.php?building_id=<?= $building_id ?>" class="card p-4 flex items-center gap-3">
            <span class="text-2xl">🔧</span>
            <span class="text-sm font-bold text-gray-700">تعمیرات</span>
        </a>
        <a href="visitors.php?building_id=<?= $building_id ?>" class="card p-4 flex items-center gap-3">
            <span class="text-2xl">🚶</span>
            <span class="text-sm font-bold text-gray-700">مهمان‌ها</span>
        </a>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
