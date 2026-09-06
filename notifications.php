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

// دریافت لیست اعلانات
$notifications = [];
$list_response = callAPI('GET', '/notifications');
if (isset($list_response['success']) && $list_response['success'] === true) {
    $notifications = $list_response['data'] ?? [];
}

$page_title = 'اعلانات من';
$header_sub = 'پیام‌ها و رویدادها';
$back_url = 'index.php';
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <!-- لیست اعلانات -->
    <h2 class="section-title">همه اعلانات</h2>

    <?php if (empty($notifications)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🔔</div>
            اعلانی برای شما ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($notifications as $notification): ?>
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
                        <span class="text-[11px] text-gray-400 flex-shrink-0"><?= fa_time_ago($notification['created_at'] ?? '') ?></span>
                    </div>
                    <?php if (empty($notification['is_read'])): ?>
                        <form method="POST" action="" class="mt-3">
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
