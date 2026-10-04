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

$logs = [];
$building_name = '';
if ($building_id > 0 && $is_manager) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $query = ['building_id' => (string) $building_id, 'limit' => '200'];
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
                        <?php foreach (['auth.' => 'ورود/خروج/ثبت‌نام', 'building.' => 'ساختمان', 'unit.' => 'واحدها', 'cost.' => 'هزینه‌ها', 'payment.' => 'پرداخت‌ها', 'ticket.' => 'تیکت‌ها', 'document.' => 'اسناد', 'penalty_setting.' => 'جریمه‌ها', 'message.' => 'پیام‌ها'] as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $action_filter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-chip btn-chip-neutral" style="margin-top:22px;">اعمال فیلتر</button>
            </div>
        </form>

        <?php if ($is_manager): ?>
            <div style="margin:-4px 0 14px;">
                <a class="btn-chip" href="list_export.php?type=audit&building_id=<?= (int) $building_id ?>" title="خروجی اکسل لاگ اقدامات">📥 خروجی اکسل</a>
            </div>
        <?php endif; ?>

        <div class="section-header-row" style="margin: 0 0 12px;">
            <h2 class="section-title">آخرین اقدام‌ها (<?= fa_digits(count($logs)) ?>)</h2>
        </div>

        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <div class="empty-icon">🗂️</div>
                هنوز اقدامی ثبت نشده است. به‌محض انجام هر عملیات، ردپای آن اینجا ثبت می‌شود.
            </div>
        <?php else: ?>
            <div class="list-filter-bar">
                <input type="search" class="form-input" data-list-search="audit-list" placeholder="🔍 جستجو در شرح اقدام، کاربر یا واحد…" style="flex:1;">
                <span class="list-count-chip" data-list-count="audit-list"></span>
            </div>
            <div class="space-y-3" data-list-items="audit-list">
                <?php foreach ($logs as $log): ?>
                    <?php
                    $l_action = (string) ($log['action'] ?? '');
                    $l_meta = json_decode((string) ($log['meta'] ?? ''), true);
                    $l_meta = is_array($l_meta) ? $l_meta : [];
                    ?>
                    <div class="card p-4">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:var(--soft-indigo,#eef2ff);font-size:19px;">
                                <?= htmlspecialchars(mb_substr(audit_action_label($l_action), 0, 2)) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars(audit_action_label($l_action)) ?></h3>
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
            <div data-list-pager="audit-list"></div>
        <?php endif; ?>

    <?php endif; ?>

</main>

<?php require_once 'includes/footer.php'; ?>
