<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

$alert_message = '';
$alert_type = 'error';
$reopen_modal = '';

// قرائت کنتور: مدیر برای همه واحدها، ساکن فقط برای واحد خودش.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];
$current_user_id = $ctx['user_id'];
$my_unit_ids = array_map(static fn($u) => (int) ($u['id'] ?? 0), $ctx['units']);

// واحدهای قابل انتخاب
$units = [];
if ($building_id > 0) {
    $units_response = callAPI('GET', '/buildings/' . $building_id . '/units');
    if (!empty($units_response['success'])) {
        $units = $units_response['data']['units'] ?? [];
    }
}
$selectable_units = $is_manager
    ? $units
    : array_values(array_filter($units, static fn($u) => in_array((int) ($u['id'] ?? 0), $my_unit_ids, true)));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if ($action === 'delete') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        $response = callAPI('DELETE', '/consumption/' . $item_id);
        if (!empty($response['success'])) {
            $alert_message = 'قرائت حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف قرائت.';
        }
    } else {
        $amount = en_digits(trim($_POST['reading_value'] ?? ''));
        $unit_id = !empty($_POST['unit_id']) ? (int) $_POST['unit_id'] : null;
        if ($amount === '' || !is_numeric($amount)) {
            $alert_message = 'مقدار قرائت را وارد کنید.';
            $reopen_modal = $action === 'update' ? 'edit-reading' : 'add-reading';
        } elseif (!$is_manager && $unit_id !== null && !in_array($unit_id, $my_unit_ids, true)) {
            $alert_message = 'شما فقط می‌توانید برای واحد خودتان قرائت ثبت کنید.';
        } else {
            $payload = [
                'unit_id' => $unit_id,
                'consumption_type' => $_POST['consumption_type'] ?? 'water',
                'reading_value' => (float) $amount,
                'reading_date' => !empty($_POST['reading_date']) ? $_POST['reading_date'] : null,
                'notes' => trim($_POST['notes'] ?? ''),
            ];
            if ($action === 'update') {
                $item_id = (int) ($_POST['item_id'] ?? 0);
                $response = callAPI('PUT', '/consumption/' . $item_id, $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'قرائت ویرایش شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش قرائت.';
                    $reopen_modal = 'edit-reading';
                }
            } else {
                $payload['building_id'] = $building_id;
                $response = callAPI('POST', '/consumption', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'قرائت با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت قرائت.';
                    $reopen_modal = 'add-reading';
                }
            }
        }
    }
}

// دریافت لیست قرائت‌ها
$readings = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/consumption', ['building_id' => $building_id]);
    if (!empty($list_response['success'])) {
        $readings = $list_response['data'] ?? [];
    }
}

// ساکن غیر مدیر فقط قرائت‌های واحد خودش را می‌بیند
if (!$is_manager) {
    $readings = array_values(array_filter($readings, static function ($r) use ($my_unit_ids, $current_user_id) {
        return in_array((int) ($r['unit_id'] ?? 0), $my_unit_ids, true)
            || (int) ($r['created_by'] ?? 0) === $current_user_id;
    }));
}

$unit_labels = [];
foreach ($units as $u) {
    $unit_labels[$u['id']] = 'واحد ' . fa_digits($u['unit_number'] ?? $u['id']);
}

$page_title = 'مصرف انرژی';
$header_sub = $building_name ?: 'قرائت کنتور';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager || !empty($selectable_units)): ?>
        <?php modal_open_button('add-reading', 'ثبت قرائت جدید'); ?>
    <?php else: ?>
        <div class="hint-card">⚡ برای ثبت قرائت، ابتدا باید واحدی به نام شما در ساختمان ثبت شده باشد.</div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">قرائت‌های ثبت‌شده (<?= fa_digits(count($readings)) ?>)</h2>
    </div>

    <?php if (empty($readings)): ?>
        <div class="empty-state">
            <div class="empty-icon">⚡</div>
            قرائتی ثبت نشده است.
            <button type="button" class="empty-action" data-modal-open="add-reading">📝 ثبت اولین قرائت</button>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($readings as $reading): ?>
                <?php
                $r_id = (int) ($reading['id'] ?? 0);
                $r_unit = (int) ($reading['unit_id'] ?? 0);
                $r_type = $reading['consumption_type'] ?? 'water';
                $r_value = $reading['reading_value'] ?? 0;
                $r_date = $reading['reading_date'] ?? '';
                $r_notes = $reading['notes'] ?? '';
                $can_modify = $is_manager
                    || in_array($r_unit, $my_unit_ids, true)
                    || (int) ($reading['created_by'] ?? 0) === $current_user_id;
                ?>
                <div class="card p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0" style="font-size:19px;">⚡</div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm">
                                <?= htmlspecialchars(consumption_type_label($r_type)) ?>
                                <span class="text-gray-400 font-normal">— <?= htmlspecialchars($unit_labels[$r_unit] ?? 'مشاعات') ?></span>
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                مقدار: <?= fa_number($r_value) ?>
                                <?php if ($r_date !== ''): ?> • <?= fa_date($r_date) ?><?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($r_notes !== ''): ?>
                        <p class="text-xs text-gray-400 mt-2 leading-6"><?= nl2br(htmlspecialchars($r_notes)) ?></p>
                    <?php endif; ?>

                    <?php if ($can_modify): ?>
                        <div class="card-actions">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-reading"
                                    data-set-item_id="<?= $r_id ?>"
                                    data-set-unit_id="<?= $r_unit ?>"
                                    data-set-consumption_type="<?= htmlspecialchars($r_type) ?>"
                                    data-set-reading_value="<?= htmlspecialchars((string) $r_value) ?>"
                                    data-set-reading_date="<?= htmlspecialchars($r_date) ?>"
                                    data-set-notes="<?= htmlspecialchars($r_notes) ?>">
                                ویرایش
                            </button>
                            <form method="POST" action="" data-confirm="این قرائت حذف شود؟" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="item_id" value="<?= $r_id ?>">
                                <button type="submit" class="btn-chip btn-chip-danger">حذف</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php modal_start('add-reading', 'ثبت قرائت کنتور', 'نوع مصرف، واحد و مقدار'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="create">
        <?php include 'includes/_consumption_form_fields.php'; ?>
        <button type="submit" class="btn-primary">ثبت قرائت</button>
    </form>
<?php modal_end(); ?>

<?php modal_start('edit-reading', 'ویرایش قرائت', 'اصلاح مقدار یا تاریخ قرائت'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="update">
        <input type="hidden" name="item_id" value="">
        <?php include 'includes/_consumption_form_fields.php'; ?>
        <button type="submit" class="btn-primary">ذخیره تغییرات</button>
    </form>
<?php modal_end(); ?>

<?php require_once 'includes/footer.php'; ?>
