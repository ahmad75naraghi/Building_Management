<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: login.php");
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

$page_title = 'گزارش‌ها';
$header_sub = $building_name ?: 'نمای کلی عملکرد ساختمان';
$back_url = 'index.php';
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

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
