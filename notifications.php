<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$alert_message = '';
$alert_type = 'success';

// علامت‌گذاری خوانده‌شده
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notification_id'])) {
    $notification_id = (int) $_POST['notification_id'];
    $response = callAPI('POST', '/notifications/' . $notification_id . '/read');
    if (isset($response['success']) && $response['success'] === true) {
        $alert_message = 'اعلان خوانده شد.';
    }
}

// خواندن همهٔ اعلان‌ها با یک کلیک
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['form_action'] ?? '') === 'mark_all_read')) {
    $response = callAPI('POST', '/notifications/read-all');
    if (isset($response['success']) && $response['success'] === true) {
        $marked = (int) ($response['data']['marked'] ?? 0);
        $alert_message = $marked > 0
            ? 'همهٔ اعلان‌ها (' . fa_digits($marked) . ' مورد) خوانده شدند.'
            : 'اعلان خوانده‌نشده‌ای وجود نداشت.';
    }
}

// دریافت لیست اعلانات
$notifications = [];
$list_response = callAPI('GET', '/notifications');
if (isset($list_response['success']) && $list_response['success'] === true) {
    $notifications = $list_response['data'] ?? [];
}

// فیلتر اعلان‌ها: همه / مالی / عمومی
$financial_types = ['payment'];
$notif_is_financial = static fn(array $n): bool => in_array(
    (string) ($n['notification_type'] ?? 'general'),
    $financial_types,
    true
);
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'financial', 'general'], true)) {
    $filter = 'all';
}
$filter_counts = ['all' => count($notifications), 'financial' => 0, 'general' => 0];
foreach ($notifications as $n_item) {
    if ($notif_is_financial($n_item)) {
        $filter_counts['financial']++;
    } else {
        $filter_counts['general']++;
    }
}
$filtered_notifications = array_values(array_filter(
    $notifications,
    static fn(array $n): bool => $filter === 'all'
        || ($filter === 'financial') === $notif_is_financial($n)
));

$page_title = 'اعلانات من';
$header_sub = 'پیام‌ها و رویدادها';
$back_url = 'index.php';
$active_nav = 'messages';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <!-- لیست اعلانات -->
    <div class="section-header-row" style="margin: 0 0 12px;">
        <h2 class="section-title">همه اعلانات</h2>
        <?php
        $unread_count = 0;
        foreach ($notifications as $n) {
            if (empty($n['is_read'])) {
                $unread_count++;
            }
        }
        ?>
        <?php if ($unread_count > 0): ?>
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="mark_all_read">
                <button type="submit" class="btn-chip btn-chip-neutral" title="خواندن همهٔ اعلان‌ها">✅ خواندن همه</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- فیلتر نوع اعلان‌ها -->
    <?php
    $filter_tabs = [
        'all' => 'همه',
        'financial' => '💰 مالی',
        'general' => '📋 عمومی',
    ];
    ?>
    <div class="flex items-center gap-2 mb-3 flex-wrap">
        <?php foreach ($filter_tabs as $f_key => $f_label): ?>
            <?php $active = $filter === $f_key; ?>
            <a href="notifications.php<?= $f_key === 'all' ? '' : '?filter=' . $f_key ?>"
               class="btn-chip <?= $active ? 'btn-chip-gold' : 'btn-chip-neutral' ?>"
               style="<?= $active ? '' : 'opacity:.75;' ?>font-size:12px;">
                <?= $f_label ?>
                <span style="opacity:.7;font-size:11px;">(<?= fa_digits($filter_counts[$f_key]) ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($filtered_notifications)): ?>
        <div class="card empty-state">
            <div class="empty-icon">🔔</div>
            <?= $filter === 'all'
                ? 'اعلانی برای شما ثبت نشده است.'
                : 'اعلانی در این بخش وجود ندارد.' ?>
            <?php if ($filter === 'all'): ?>
                <a class="empty-action" href="index.php">🏠 بازگشت به داشبورد</a>
            <?php else: ?>
                <a class="empty-action" href="notifications.php">نمایش همهٔ اعلان‌ها</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php $current_day = null; ?>
            <?php foreach ($filtered_notifications as $notification): ?>
                <?php
                // گروه‌بندی روزانه: هنگام تغییر روز، جداکنندهٔ «امروز/دیروز/تاریخ» بگذار
                $n_day = date('Y-m-d', strtotime((string) ($notification['created_at'] ?? 'now')));
                if ($n_day !== $current_day):
                    $current_day = $n_day;
                ?>
                    <div class="list-day-divider"><?= htmlspecialchars(fa_day_label($notification['created_at'] ?? 'now')) ?></div>
                <?php endif; ?>
                <div class="card p-4 <?= empty($notification['is_read']) ? 'border-r-4 border-r-blue-600 bg-blue-50/40' : '' ?>">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="w-10 h-10 rounded-xl <?= empty($notification['is_read']) ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-400' ?> flex items-center justify-center flex-shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($notification['title'] ?? 'بدون عنوان') ?></h3>
                                <?php if (!empty($notification['message'])): ?>
                                    <p class="text-xs text-gray-500 mt-0.5 leading-5"><?= htmlspecialchars($notification['message']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="text-[11px] text-gray-400 flex-shrink-0"><?= fa_smart_time($notification['created_at'] ?? '') ?></span>
                    </div>
                    <?php if (empty($notification['is_read'])): ?>
                        <form method="POST" action="" class="mt-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                            <button type="submit" class="w-full bg-blue-50 hover:bg-blue-100 text-blue-700 text-sm font-bold py-2.5 rounded-xl transition-all">
                                علامت‌گذاری به‌عنوان خوانده‌شده
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php require_once 'includes/page_tail.php'; ?>
