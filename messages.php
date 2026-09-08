<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);
$peer_id = (int) ($_GET['with'] ?? 0);

$alert_message = '';
$alert_type = 'error';

// ارسال پیام
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'send_message') {
    $recipient_id = (int) ($_POST['recipient_id'] ?? 0);
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($building_id <= 0 || $recipient_id <= 0 || $body === '') {
        $alert_message = 'متن پیام را وارد کنید.';
    } else {
$response = callAPI('POST', '/messages', [
            'building_id' => $building_id,
            'recipient_id' => $recipient_id,
            'body' => $body,
        ]);
        if (!empty($response['success'])) {
            header('Location: messages.php?building_id=' . $building_id . '&with=' . $recipient_id);
            exit;
        }
        $alert_message = $response['message'] ?? 'ارسال پیام ناموفق بود.';
    }
}

// نام ساختمان
$building_name = '';
if ($building_id > 0) {
$building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
}

// حالت گفتگو یا فهرست
$thread = [];
$peer_name = '';
$conversations = [];
$members = [];
// فهرست اعضا یک‌بار خوانده می‌شود و هم برای نام طرف گفتگو و هم برای
// «شروع گفتگوی جدید» استفاده می‌شود (جلوگیری از دیسپچ تکراری).
if ($building_id > 0) {
    $members_response = callAPI('GET', '/buildings/' . $building_id . '/members');
    if (!empty($members_response['success'])) {
        $my_id = (int) ($_SESSION['user_id'] ?? 0);
        foreach ($members_response['data'] ?? [] as $m) {
            if ((int) ($m['user_id'] ?? 0) !== $my_id) {
                $members[] = $m;
            }
        }
    }
}
if ($peer_id > 0 && $building_id > 0) {
    $thread_response = callAPI('GET', '/messages/thread/' . $peer_id, ['building_id' => $building_id]);
    if (!empty($thread_response['success'])) {
        $thread = $thread_response['data'] ?? [];
    }
    // نام طرف گفتگو از فهرست اعضای خوانده‌شده
    foreach ($members as $m) {
        if ((int) ($m['user_id'] ?? 0) === $peer_id) {
            $peer_name = $m['name'] ?? 'کاربر';
            break;
        }
    }
    if ($peer_name === '') {
        $peer_name = 'کاربر';
    }
} elseif ($building_id > 0) {
    $conv_response = callAPI('GET', '/messages/conversations', ['building_id' => $building_id]);
    if (!empty($conv_response['success'])) {
        $conversations = $conv_response['data'] ?? [];
    }
}

$page_title = 'پیام‌های من';
$header_sub = $peer_id > 0 ? $peer_name : ($building_name ?: 'صندوق پیام ساختمان');
$back_url = $peer_id > 0
    ? 'messages.php?building_id=' . $building_id
    : 'dashboard.php?building_id=' . $building_id;
$nav_active = 'messages';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($peer_id > 0): ?>
        <!-- ==================== نمای گفتگو ==================== -->
        <div class="card p-0 overflow-hidden" style="display:flex;flex-direction:column;min-height:60vh;">
            <div class="p-4 border-b border-gray-100 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold">
                    <?= htmlspecialchars(mb_substr($peer_name, 0, 1, 'UTF-8')) ?>
                </div>
                <div>
                    <p class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($peer_name) ?></p>
                    <p class="text-[11px] text-gray-400">گفتگوی خصوصی ساختمان</p>
                </div>
            </div>

            <div class="chat-thread-area flex-1 p-4 space-y-3 overflow-y-auto" style="background:#f8fafc;max-height:55vh;">
                <?php if (empty($thread)): ?>
                    <p class="text-center text-xs text-gray-400 py-8">هنوز پیامی رد و بدل نشده است. اولین پیام را شما بفرستید! 👋</p>
                <?php endif; ?>
                <?php $my_id = (int) ($_SESSION['user_id'] ?? 0); ?>
                <?php foreach ($thread as $msg): ?>
                    <?php $mine = (int) ($msg['sender_id'] ?? 0) === $my_id; ?>
                    <div class="flex <?= $mine ? 'justify-start flex-row-reverse' : 'justify-start' ?>">
                        <div style="max-width:75%;background:<?= $mine ? 'var(--gold-primary)' : '#fff' ?>;color:<?= $mine ? '#fff' : 'var(--text-dark)' ?>;
                                      border-radius:14px;padding:8px 12px;font-size:13px;line-height:1.8;
                                      box-shadow:0 1px 2px rgba(3,12,34,.06);">
                            <p style="white-space:pre-wrap;word-break:break-word;"><?= htmlspecialchars($msg['body'] ?? '') ?></p>
                            <p style="font-size:9px;opacity:.7;margin-top:2px;text-align:left;">
                                <?= !empty($msg['created_at']) ? fa_datetime($msg['created_at']) : '' ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="POST" action="" class="p-3 border-t border-gray-100 flex items-end gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="send_message">
                <input type="hidden" name="recipient_id" value="<?= $peer_id ?>">
                <textarea name="body" rows="2" required maxlength="2000" class="form-input" style="flex:1;"
                          placeholder="پیام خود را بنویسید…"></textarea>
                <button type="submit" class="btn-primary" style="height:44px;">ارسال</button>
            </form>
        </div>

    <?php else: ?>
        <!-- ==================== فهرست گفتگوها ==================== -->

        <!-- شروع گفتگوی جدید -->
        <details class="card p-3 mb-4">
            <summary class="font-bold text-gray-700 text-sm cursor-pointer">✍️ شروع گفتگوی جدید</summary>
            <div class="flex flex-wrap gap-2 mt-3">
                <?php if (empty($members)): ?>
                    <p class="text-xs text-gray-400">عضو دیگری در این ساختمان نیست.</p>
                <?php endif; ?>
                <?php foreach ($members as $m): ?>
                    <a href="messages.php?building_id=<?= $building_id ?>&with=<?= (int) ($m['user_id'] ?? 0) ?>"
                       class="text-xs px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 hover:bg-gray-200" style="text-decoration:none;">
                        <?= htmlspecialchars($m['name'] ?? 'کاربر') ?>
                        <?php if (($m['role'] ?? '') === 'manager'): ?><span style="color:var(--gold-primary);">★ مدیر</span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>

        <?php if (empty($conversations)): ?>
            <div class="empty-state">
                <div style="font-size: 34px; margin-bottom: 8px;">💬</div>
                هنوز گفتگویی ندارید. از بخش «شروع گفتگوی جدید» پیام بدهید.
            </div>
        <?php else: ?>
            <div class="space-y-2">
                <?php $current_day = null; ?>
                <?php foreach ($conversations as $conv): ?>
                    <?php $last = $conv['last_message'] ?? []; ?>
                    <?php
                    // گروه‌بندی روزانه بر اساس زمان آخرین پیام
                    $c_ts = strtotime((string) ($last['created_at'] ?? ''));
                    $c_day = $c_ts === false ? null : date('Y-m-d', $c_ts);
                    if ($c_day !== null && $c_day !== $current_day):
                        $current_day = $c_day;
                    ?>
                        <div class="list-day-divider"><?= htmlspecialchars(fa_day_label($last['created_at'])) ?></div>
                    <?php endif; ?>
                    <a href="messages.php?building_id=<?= $building_id ?>&with=<?= (int) ($conv['other_id'] ?? 0) ?>"
                       class="card p-3 flex items-center gap-3" style="display:flex;text-decoration:none;">
                        <div class="w-11 h-11 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold flex-shrink-0">
                            <?= htmlspecialchars(mb_substr((string) ($conv['other_name'] ?? 'ک'), 0, 1, 'UTF-8')) ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($conv['other_name'] ?? 'کاربر') ?></p>
                                <span class="text-[10px] text-gray-400 flex-shrink-0">
                                    <?= !empty($last['created_at']) ? fa_smart_time($last['created_at']) : '' ?>
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 truncate mt-0.5">
                                <?= !empty($conv['last_from_me']) ? 'شما: ' : '' ?><?= htmlspecialchars(mb_substr((string) ($last['body'] ?? ''), 0, 60, 'UTF-8')) ?>
                            </p>
                        </div>
                        <?php if ((int) ($conv['unread'] ?? 0) > 0): ?>
                            <span class="nav-badge" style="position:static;background:var(--gold-primary);color:#fff;">
                                <?= fa_digits($conv['unread']) ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="mt-4">
            <a href="notifications.php" class="text-xs text-gray-400" style="text-decoration:none;">🔔 اعلانات سیستم ←</a>
        </div>
    <?php endif; ?>

</main>

require_once 'includes/footer.php'; ?>
