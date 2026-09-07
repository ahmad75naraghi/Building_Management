<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

// امروز (شمسی و میلادی)
$today_ts = time();
[$today_jy, $today_jm, $today_jd] = gregorian_to_jalali(
    (int) date('Y', $today_ts),
    (int) date('m', $today_ts),
    (int) date('d', $today_ts)
);
$today_g = date('Y-m-d');

// ماه نمایش‌داده‌شده: پارامتر m به‌صورت شمسی (YYYY-MM)، پیش‌فرض ماه جاری
[$view_jy, $view_jm] = [$today_jy, $today_jm];
if (preg_match('/^(\d{4})-(\d{1,2})$/', (string) ($_GET['m'] ?? ''), $mm)) {
    $view_jy = (int) $mm[1];
    $view_jm = (int) $mm[2];
}
if ($view_jm < 1 || $view_jm > 12) {
    $view_jm = $today_jm;
}

// ناوبری ماه قبل/بعد (شمسی)
$prev_jy = $view_jy;
$prev_jm = $view_jm - 1;
if ($prev_jm < 1) {
    $prev_jm = 12;
    $prev_jy--;
}
$next_jy = $view_jy;
$next_jm = $view_jm + 1;
if ($next_jm > 12) {
    $next_jm = 1;
    $next_jy++;
}
$prev_url = sprintf('calendar.php?m=%04d-%02d&building_id=%d', $prev_jy, $prev_jm, $building_id);
$next_url = sprintf('calendar.php?m=%04d-%02d&building_id=%d', $next_jy, $next_jm, $building_id);
$today_url = sprintf('calendar.php?m=%04d-%02d&building_id=%d', $today_jy, $today_jm, $building_id);

// دریافت رویدادها از API
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

// چیدمان رویدادها بر اساس روز شمسی (کلید: "jy-jm-jd")
// یک آرایه برای شبکه تقویم و یک آرایه کامل برای پنل جزئیات هر روز
$eventsByDay = [];   // jy-jm-jd => [['title', 'time', 'type', 'status_label', 'color'] ...]
$upcoming = [];      // رویدادهای ۱۴ روز آینده

$horizon_ts = strtotime($today_g) + (14 * 86400); // افق «پیش رو»: ۱۴ روز

foreach ($meetings as $meeting) {
    $status = (string) ($meeting['status'] ?? 'scheduled');
    if ($status === 'cancelled') {
        continue;
    }
    $raw = (string) ($meeting['meeting_date'] ?? '');
    $ts = strtotime($raw);
    if ($ts === false) {
        continue;
    }
    [$jy, $jm, $jd] = gregorian_to_jalali((int) date('Y', $ts), (int) date('m', $ts), (int) date('d', $ts));
    $key = "{$jy}-{$jm}-{$jd}";
    $item = [
        'type' => 'meeting',
        'title' => (string) ($meeting['title'] ?: 'جلسه'),
        'time' => date('H:i', $ts),
        'location' => (string) ($meeting['location'] ?? ''),
        'status_label' => meeting_status_label($status),
        'ts' => $ts,
    ];
    $eventsByDay[$key][] = $item;
    if ($ts >= strtotime($today_g) && $ts < $horizon_ts) {
        $upcoming[] = $item + ['date_ts' => strtotime(date('Y-m-d', $ts))];
    }
}

foreach ($bookings as $booking) {
    $status = (string) ($booking['status'] ?? 'pending');
    if ($status === 'cancelled') {
        continue;
    }
    $date = substr((string) ($booking['booking_date'] ?? ''), 0, 10);
    if ($date === '' || strtotime($date) === false) {
        continue;
    }
    $start = (string) ($booking['start_time'] ?? '');
    $end = (string) ($booking['end_time'] ?? '');
    // رویدادهای تمام‌روز (بدون ساعت) ساعت ۹ صبح فرض می‌شوند تا در لیست «پیش رو» دیده شوند
    $event_ts = strtotime($date . ' ' . ($start !== '' ? $start : '09:00:00'));
    if ($event_ts === false) {
        continue;
    }
    [$jy, $jm, $jd] = gregorian_to_jalali((int) date('Y', $event_ts), (int) date('m', $event_ts), (int) date('d', $event_ts));
    $key = "{$jy}-{$jm}-{$jd}";
    $time_label = '';
    if ($start !== '') {
        $time_label = substr($start, 0, 5) . ($end !== '' ? '–' . substr($end, 0, 5) : '');
    }
    $item = [
        'type' => 'booking',
        'title' => 'رزرو مشاعات',
        'time' => $time_label,
        'location' => '',
        'status_label' => booking_status_label($status),
        'ts' => $event_ts,
    ];
    $eventsByDay[$key][] = $item;
    if ($event_ts >= strtotime($today_g) && $event_ts < $horizon_ts) {
        $upcoming[] = $item + ['date_ts' => strtotime($date)];
    }
}

// مرتب‌سازی رویدادهای پیش رو بر اساس زمان
usort($upcoming, fn($a, $b) => $a['ts'] <=> $b['ts']);

// ساخت شبکه ماه شمسی
$daysInMonth = jalali_month_length($view_jy, $view_jm);
[$g_first_y, $g_first_m, $g_first_d] = jalali_to_gregorian($view_jy, $view_jm, 1);
$firstTs = mktime(12, 0, 0, $g_first_m, $g_first_d, $g_first_y); // ظهر: دور از لبه نیمه‌شب
$satIndex = jalali_weekday_index($firstTs); // شنبه=۰

$cells = [];
for ($i = 0; $i < $satIndex; $i++) {
    $cells[] = null;
}
for ($day = 1; $day <= $daysInMonth; $day++) {
    [$gy, $gm, $gd] = jalali_to_gregorian($view_jy, $view_jm, $day);
    $ts = mktime(12, 0, 0, $gm, $gd, $gy);
    $key = "{$view_jy}-{$view_jm}-{$day}";
    $cells[] = [
        'jday' => $day,
        'gday' => $gd,
        'gmonth' => $gm,
        'key' => $key,
        'is_today' => ($gy === (int) date('Y') && $gm === (int) date('m') && $gd === (int) date('d')),
        'events' => array_slice($eventsByDay[$key] ?? [], 0, 2),
        'more' => max(0, count($eventsByDay[$key] ?? []) - 2),
        'has_events' => !empty($eventsByDay[$key]),
    ];
}
while (count($cells) % 7 !== 0) {
    $cells[] = null;
}

$weekdayNames = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

// داده‌های پنل جزئیات روز برای جاوااسکریپت
$panelData = [];
foreach ($eventsByDay as $key => $items) {
    $panelData[$key] = array_map(fn($it) => [
        't' => $it['title'],
        'time' => $it['time'],
        'loc' => $it['location'],
        'type' => $it['type'],
        'st' => $it['status_label'],
    ], $items);
}
$panelJson = json_encode($panelData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

$page_title = 'تقویم';
$header_sub = $building_name ?: (jalali_month_name($view_jm) . ' ' . fa_digits($view_jy));
$back_url = 'index.php';
$active_nav = 'calendar';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <?php if ($building_id <= 0): ?>
        <div class="card p-6 text-center text-sm text-gray-500 leading-7">
            برای مشاهده تقویم، ابتدا یک ساختمان انتخاب یا ثبت کنید.
            <div class="mt-3"><a href="index.php" class="text-blue-600 font-bold">رفتن به داشبورد</a></div>
        </div>
    <?php else: ?>

        <!-- ناوبری ماه شمسی -->
        <div class="flex items-center justify-between mb-4">
            <a href="<?= $prev_url ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold w-9 h-9 flex items-center justify-center rounded-xl transition-colors" aria-label="ماه قبل">›</a>
            <div class="text-center">
                <h2 class="font-bold text-gray-800"><?= jalali_month_name($view_jm) ?> <?= fa_digits($view_jy) ?></h2>
                <?php if ($view_jy !== $today_jy || $view_jm !== $today_jm): ?>
                    <a href="<?= $today_url ?>" class="text-[11px] text-blue-600 font-bold">بازگشت به ماه جاری</a>
                <?php endif; ?>
            </div>
            <a href="<?= $next_url ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-bold w-9 h-9 flex items-center justify-center rounded-xl transition-colors" aria-label="ماه بعد">‹</a>
        </div>

        <!-- شبکه تقویم شمسی -->
        <div class="card p-4">
            <div class="grid grid-cols-7 gap-1 mb-2">
                <?php foreach ($weekdayNames as $wd): ?>
                    <div class="text-center text-[11px] font-bold text-gray-400 py-1"><?= $wd ?></div>
                <?php endforeach; ?>
            </div>
            <div class="grid grid-cols-7 gap-1">
                <?php foreach ($cells as $cell): ?>
                    <?php if ($cell === null): ?>
                        <div class="aspect-square rounded-lg"></div>
                    <?php else: ?>
                        <button type="button"
                                data-day="<?= $cell['key'] ?>"
                                class="cal-day aspect-square rounded-lg p-1 flex flex-col text-left transition-colors cursor-pointer <?= $cell['is_today'] ? 'bg-blue-600 text-white ring-2 ring-blue-300' : ($cell['has_events'] ? 'bg-gray-50 hover:bg-blue-50 ring-1 ring-blue-100' : 'bg-gray-50 hover:bg-blue-50') ?>">
                            <span class="flex items-center justify-between">
                                <span class="text-[11px] font-bold <?= $cell['is_today'] ? 'text-white' : 'text-gray-600' ?>"><?= fa_digits($cell['jday']) ?></span>
                                <span class="text-[8px] <?= $cell['is_today'] ? 'text-white/70' : 'text-gray-300' ?>"><?= fa_digits($cell['gday']) ?>/<?= fa_digits($cell['gmonth']) ?></span>
                            </span>
                            <span class="flex-1 flex flex-col gap-0.5 mt-0.5 overflow-hidden">
                                <?php foreach ($cell['events'] as $ev): ?>
                                    <span class="text-[8px] leading-tight px-1 py-0.5 rounded <?= $ev['type'] === 'meeting' ? 'bg-purple-100 text-purple-700' : 'bg-green-100 text-green-700' ?> <?= $cell['is_today'] ? 'bg-white/25 text-white' : '' ?> truncate">
                                        <?= htmlspecialchars($ev['title']) ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if ($cell['more'] > 0): ?>
                                    <span class="text-[8px] text-gray-400 <?= $cell['is_today'] ? 'text-white/80' : '' ?>">+<?= fa_digits($cell['more']) ?></span>
                                <?php endif; ?>
                            </span>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- پنل جزئیات روز انتخابی -->
        <div id="day-panel" class="card p-4 mt-4 hidden">
            <div class="flex items-center justify-between mb-3">
                <h3 id="day-panel-title" class="font-bold text-gray-800 text-sm"></h3>
                <button type="button" id="day-panel-close" class="text-gray-400 hover:text-gray-600 text-lg leading-none" aria-label="بستن">×</button>
            </div>
            <div id="day-panel-body" class="space-y-2"></div>
        </div>

        <!-- رویدادهای پیش رو (۱۴ روز آینده) -->
        <?php if (!empty($upcoming)): ?>
            <div class="card p-4 mt-4">
                <h3 class="font-bold text-gray-800 text-sm mb-3">رویدادهای پیشِ رو</h3>
                <div class="space-y-2">
                    <?php foreach (array_slice($upcoming, 0, 10) as $ev): ?>
                        <?php [$ev_jy, $ev_jm, $ev_jd] = gregorian_to_jalali((int) date('Y', $ev['ts']), (int) date('m', $ev['ts']), (int) date('d', $ev['ts'])); ?>
                        <a href="<?= $ev['type'] === 'meeting' ? 'meetings.php' : 'bookings.php' ?>?building_id=<?= $building_id ?>"
                           class="flex items-center gap-3 p-2 rounded-xl bg-gray-50 hover:bg-blue-50 transition-colors">
                            <span class="w-2.5 h-2.5 rounded-full shrink-0 <?= $ev['type'] === 'meeting' ? 'bg-purple-400' : 'bg-green-400' ?>"></span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-sm font-bold text-gray-700 truncate"><?= htmlspecialchars($ev['title']) ?></span>
                                <span class="block text-[11px] text-gray-400">
                                    <?= fa_digits($ev_jd) ?> <?= jalali_month_name($ev_jm) ?>
                                    <?= $ev['time'] !== '' ? '، ساعت ' . fa_digits($ev['time']) : '' ?>
                                    <?= $ev['location'] !== '' ? ' — ' . htmlspecialchars($ev['location']) : '' ?>
                                </span>
                            </span>
                            <span class="text-[11px] font-bold whitespace-nowrap <?= $ev['date_ts'] <= time() ? 'text-red-500' : 'text-blue-600' ?>"><?= fa_days_until(date('Y-m-d', $ev['ts'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- راهنما -->
        <div class="flex items-center gap-4 mt-4 text-xs text-gray-500">
            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-purple-400 inline-block"></span> جلسه</span>
            <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-green-400 inline-block"></span> رزرو مشاع</span>
            <span class="text-gray-300">|</span>
            <span>برای جزئیات، روی روز بزنید</span>
            <a href="meetings.php?building_id=<?= $building_id ?>" class="mr-auto text-blue-600 font-bold">جلسات</a>
            <a href="bookings.php?building_id=<?= $building_id ?>" class="text-blue-600 font-bold">رزروها</a>
        </div>

        <script>
            (function () {
                var DAY_EVENTS = <?= $panelJson ?>;
                var panel = document.getElementById('day-panel');
                var panelTitle = document.getElementById('day-panel-title');
                var panelBody = document.getElementById('day-panel-body');
                var closeBtn = document.getElementById('day-panel-close');
                var selected = null;

                function esc(s) {
                    var d = document.createElement('div');
                    d.textContent = s == null ? '' : String(s);
                    return d.innerHTML;
                }

                var FA_DIGITS = { '0': '۰', '1': '۱', '2': '۲', '3': '۳', '4': '۴', '5': '۵', '6': '۶', '7': '۷', '8': '۸', '9': '۹' };
                function faNum(s) {
                    return String(s == null ? '' : s).replace(/[0-9]/g, function (d) { return FA_DIGITS[d]; });
                }

                function openDay(btn) {
                    var key = btn.getAttribute('data-day');
                    if (!key) return;
                    if (selected) selected.classList.remove('ring-2', 'ring-blue-400');
                    selected = btn;
                    btn.classList.add('ring-2', 'ring-blue-400');

                    var parts = key.split('-').map(Number);
                    panelTitle.textContent = faNum(parts[2]) + ' / ' + faNum(parts[1]) + ' / ' + faNum(parts[0]) + ' (شمسی)';

                    var items = DAY_EVENTS[key] || [];
                    if (!items.length) {
                        panelBody.innerHTML = '<p class="text-sm text-gray-400">در این روز رویدادی ثبت نشده است.</p>';
                    } else {
                        panelBody.innerHTML = items.map(function (ev) {
                            var color = ev.type === 'meeting' ? 'bg-purple-100 text-purple-700' : 'bg-green-100 text-green-700';
                            return '<div class="flex items-center gap-3 p-2 rounded-xl bg-gray-50">'
                                + '<span class="text-[11px] font-bold px-2 py-1 rounded ' + color + '">' + esc(ev.type === 'meeting' ? 'جلسه' : 'رزرو') + '</span>'
                                + '<span class="flex-1 min-w-0">'
                                + '<span class="block text-sm font-bold text-gray-700">' + esc(ev.t) + '</span>'
                                + '<span class="block text-[11px] text-gray-400">'
                                + (ev.time ? 'ساعت ' + esc(faNum(ev.time)) : '')
                                + (ev.loc ? (ev.time ? ' — ' : '') + esc(ev.loc) : '')
                                + (ev.st ? ' — ' + esc(ev.st) : '')
                                + '</span></span></div>';
                        }).join('');
                    }
                    panel.classList.remove('hidden');
                }

                document.querySelectorAll('.cal-day').forEach(function (btn) {
                    btn.addEventListener('click', function () { openDay(btn); });
                });
                closeBtn.addEventListener('click', function () {
                    panel.classList.add('hidden');
                    if (selected) selected.classList.remove('ring-2', 'ring-blue-400');
                    selected = null;
                });
            })();
        </script>

    <?php endif; ?>

</main>

<?php require_once 'includes/page_tail.php'; ?>
