<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

// ماه جاری (پیش‌فرض: ماه فعلی) — قالب YYYY-MM
$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? ''))
    ? (string) $_GET['m']
    : date('Y-m');
[$year, $monthNum] = array_map('intval', explode('-', $month));

// ناوبری ماه قبل/بعد
$prev = date('Y-m', mktime(0, 0, 0, $monthNum - 1, 1, $year));
$next = date('Y-m', mktime(0, 0, 0, $monthNum + 1, 1, $year));

// دریافت رویدادها
$meetings = [];
$bookings = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $m_response = callAPI('GET', '/meetings', ['building_id' => $building_id]);
    if (isset($m_response['success']) && $m_response['success'] === true) {
        $meetings = $m_response['data'] ?? [];
    }
    $b_response = callAPI('GET', '/bookings', ['building_id' => $building_id]);
    if (isset($b_response['success']) && $b_response['success'] === true) {
        $bookings = $b_response['data'] ?? [];
    }
}

// نقشه رویدادها بر اساس روز (Y-m-d => array)
$eventsByDay = [];
foreach ($meetings as $meeting) {
    $d = substr((string) ($meeting['meeting_date'] ?? ''), 0, 10);
    if ($d !== '') {
        $eventsByDay[$d][] = ['type' => 'meeting', 'title' => $meeting['title'] ?? 'جلسه'];
    }
}
foreach ($bookings as $booking) {
    $d = substr((string) ($booking['booking_date'] ?? ''), 0, 10);
    if ($d !== '') {
        $eventsByDay[$d][] = ['type' => 'booking', 'title' => 'رزرو مشاع'];
    }
}

// ساخت شبکه ماه
$daysInMonth = (int) date('t', mktime(0, 0, 0, $monthNum, 1, $year));
$firstWeekday = (int) date('w', mktime(0, 0, 0, $monthNum, 1, $year)); // 0=یکشنبه
// تبدیل به شنبه-محور: شنبه=0 ... جمعه=6
$satIndex = ($firstWeekday + 1) % 7;

$cells = [];
for ($i = 0; $i < $satIndex; $i++) {
    $cells[] = null; // خالی قبل از اول ماه
}
for ($day = 1; $day <= $daysInMonth; $day++) {
    $cells[] = $day;
}
while (count($cells) % 7 !== 0) {
    $cells[] = null;
}

$gregorianMonths = ['ژانویه', 'فوریه', 'مارس', 'آوریل', 'مه', 'ژوئن', 'ژوئیه', 'اوت', 'سپتامبر', 'اکتبر', 'نوامبر', 'دسامبر'];
$weekdayNames = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
$today = date('Y-m-d');

$page_title = 'تقویم';
$header_sub = $building_name ?: ($gregorianMonths[$monthNum - 1] . ' ' . $year);
$back_url = 'index.php';
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <!-- ناوبری ماه -->
    <div class="flex items-center justify-between mb-4">
        <a href="calendar.php?m=<?= $prev ?>&building_id=<?= $building_id ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold w-9 h-9 flex items-center justify-center rounded-xl transition-colors" aria-label="ماه قبل">›</a>
        <h2 class="font-bold text-gray-800"><?= $gregorianMonths[$monthNum - 1] ?> <?= fa_digits($year) ?></h2>
        <a href="calendar.php?m=<?= $next ?>&building_id=<?= $building_id ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold w-9 h-9 flex items-center justify-center rounded-xl transition-colors" aria-label="ماه بعد">‹</a>
    </div>

    <!-- شبکه تقویم -->
    <div class="card p-4">
        <div class="grid grid-cols-7 gap-1 mb-2">
            <?php foreach ($weekdayNames as $wd): ?>
                <div class="text-center text-[11px] font-bold text-gray-400 py-1"><?= $wd ?></div>
            <?php endforeach; ?>
        </div>
        <div class="grid grid-cols-7 gap-1">
            <?php foreach ($cells as $day): ?>
                <?php if ($day === null): ?>
                    <div class="aspect-square rounded-lg"></div>
                <?php else: ?>
                    <?php
                    $dayKey = sprintf('%04d-%02d-%02d', $year, $monthNum, $day);
                    $dayEvents = $eventsByDay[$dayKey] ?? [];
                    $isToday = $dayKey === $today;
                    ?>
                    <div class="aspect-square rounded-lg p-1 flex flex-col <?= $isToday ? 'bg-blue-600 text-white' : 'bg-gray-50 hover:bg-blue-50' ?>">
                        <span class="text-[11px] font-bold <?= $isToday ? 'text-white' : 'text-gray-600' ?>"><?= fa_digits($day) ?></span>
                        <div class="flex-1 flex flex-col gap-0.5 mt-0.5 overflow-hidden">
                            <?php foreach (array_slice($dayEvents, 0, 3) as $ev): ?>
                                <span class="text-[8px] leading-tight px-1 py-0.5 rounded <?= $ev['type'] === 'meeting' ? 'bg-purple-100 text-purple-700' : 'bg-green-100 text-green-700' ?> <?= $isToday ? 'bg-white/25 text-white' : '' ?> truncate">
                                    <?= htmlspecialchars($ev['title']) ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (count($dayEvents) > 3): ?>
                                <span class="text-[8px] text-gray-400 <?= $isToday ? 'text-white/80' : '' ?>">+<?= fa_digits(count($dayEvents) - 3) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- راهنما -->
    <div class="flex items-center gap-4 mt-4 text-xs text-gray-500">
        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-purple-400 inline-block"></span> جلسه</span>
        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-green-400 inline-block"></span> رزرو مشاع</span>
        <a href="meetings.php?building_id=<?= $building_id ?>" class="mr-auto text-blue-600 font-bold">جلسات</a>
        <a href="bookings.php?building_id=<?= $building_id ?>" class="text-blue-600 font-bold">رزروها</a>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
