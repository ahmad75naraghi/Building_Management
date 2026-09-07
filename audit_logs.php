<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);
$action_filter = trim((string) ($_GET['action'] ?? ''));

$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

/* برچسب فارسی اقدام‌های ممیزی */
$audit_action_labels = [
    'auth.login' => '🔑 ورود موفق',
    'auth.login_failed' => '⛔ تلاش ورود ناموفق',
    'auth.logout' => '🚪 خروج',
    'auth.register' => '🆕 ثبت‌نام',
    'building.create' => '🏢 ایجاد ساختمان',
    'building.update' => '🏢 ویرایش ساختمان',
    'building.delete' => '🗑️ حذف ساختمان',
    'unit.create' => ' ایجاد واحد',
    'unit.update' => '🏠 ویرایش واحد',
    'unit.delete' => '🗑️ حذف واحد',
    'cost.create' => '💰 ثبت هزینه',
    'cost.update' => '💰 ویرایش هزینه',
    'cost.delete' => '🗑️ حذف هزینه',
    'cost.issue' => '📨 صدور هزینه برای مخاطبان',
    'payment.submit' => '💳 ثبت پرداخت',
    'payment.receipt' => '🧾 آپلود رسید پرداخت',
    'payment.confirm' => '✅ تأیید پرداخت',
    'payment.reject' => '❌ رد پرداخت',
    'penalty_setting.create' => '⚙️ ثبت تنظیم جریمه',
    'penalty_setting.update' => '⚙️ ویرایش تنظیم جریمه',
    'penalty_setting.delete' => '⚙️ حذف تنظیم جریمه',
    'ticket.create' => '🎫 ثبت تیکت',
    'ticket.update' => '🎫 ویرایش تیکت',
    'ticket.delete' => '🗑️ حذف تیکت',
    'ticket.comment' => '💬 دیدگاه روی تیکت',
    'document.create' => '📄 ثبت سند',
    'document.replace_file' => '🔄 تعویض فایل سند',
];

function audit_action_label(string $action, array $labels): string
{
    if (isset($labels[$action])) {
        return $labels[$action];
    }
    foreach ($labels as $key => $label) {
        if (str_starts_with($action, $key)) {
            return $label;
        }
    }
    return '🔸 ' . $action;
}

$logs = [];
$building_name = '';
if ($building_id > 0 && $is_manager) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $query = ['building_id' => $building_id, 'limit' => '200'];
    if ($action_filter !== '') {
        $query['action'] = $action_filter;
    }
    $logs_response = callAPI('GET', '/audit-logs', $query);
    if (!empty($logs_response['success'])) {
        $logs = $logs_response['data'] ?? [];
    }
}

$page_title = 'لاگ اقدامات';
$header_sub = $building_name ?: 'ممیزی اقدامات کاربران';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if (!$is_manager): ?>
        <div class="hint-card">🔒 لاگ اقدامات فقط برای مدیر ساختمان قابل مشاهده است.</div>
    <?php else: ?>

        <form method="GET" action="" class="card p-4" style="margin-bottom:14px;">
            <input type="hidden" name="building_id" value="<?= $building_id ?>">
            <div class="flex items-center gap-3">
                <div class="flex-1">
                    <label class="form-label">نوع اقدام</label>
                    <select name="action" class="form-input">
                        <option value="">همه اقدام‌ها</option>
                        <?php foreach (['auth.' => 'ورود/خروج/ثبت‌نام', 'building.' => 'ساختمان', 'unit.' => 'واحدها', 'cost.' => 'هزینه‌ها', 'payment.' => 'پرداخت‌ها', 'ticket.' => 'تیکت‌ها', 'document.' => 'اسناد', 'penalty_setting.' => 'جریمه‌ها'] as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $action_filter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-chip btn-chip-neutral" style="margin-top:22px;">اعمال فیلتر</button>
            </div>
        </form>

        <div class="section-header-row" style="margin: 0 0 12px;">
            <h2 class="section-title">آخرین اقدام‌ها (<?= fa_digits(count($logs)) ?>)</h2>
        </div>

        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <div style="font-size: 34px; margin-bottom: 8px;">🗂️</div>
                هنوز اقدامی ثبت نشده است.
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($logs as $log): ?>
                    <?php
                    $l_action = (string) ($log['action'] ?? '');
                    $l_meta = json_decode((string) ($log['meta'] ?? ''), true);
                    $l_meta = is_array($l_meta) ? $l_meta : [];
                    ?>
                    <div class="card p-4">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:#eef2ff;font-size:19px;">
                                <?= htmlspecialchars(mb_substr(audit_action_label($l_action, $audit_action_labels), 0, 2)) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars(audit_action_label($l_action, $audit_action_labels)) ?></h3>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    👤 <?= htmlspecialchars($log['user_name'] ?? 'سیستم') ?>
                                    •  <?= fa_datetime($log['created_at'] ?? '') ?>
                                    <?php if (!empty($l_meta['title'])): ?>• «<?= htmlspecialchars((string) $l_meta['title']) ?>»<?php endif; ?>
                                    <?php if (!empty($l_meta['unit_number'])): ?>• واحد <?= fa_digits($l_meta['unit_number']) ?><?php endif; ?>
                                    <?php if (isset($l_meta['amount_paid']) && $l_meta['amount_paid'] !== null): ?>• <?= fa_number((float) $l_meta['amount_paid']) ?> تومان<?php endif; ?>
                                    <?php if (!empty($l_meta['reason'])): ?>• دلیل: <?= htmlspecialchars((string) $l_meta['reason']) ?><?php endif; ?>
                                </p>
                            </div>
                            <span class="chip chip-gray flex-shrink-0" dir="ltr"><?= htmlspecialchars($l_action) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</main>

<?php require_once 'includes/footer.php'; ?>
