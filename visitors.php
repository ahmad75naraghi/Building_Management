<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: login.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

$alert_message = '';
$alert_type = 'error';
$reopen_modal = '';

// نقش کاربر: هر عضو می‌تواند مهمان ثبت کند؛ ویرایش/حذف برای مدیر یا ثبت‌کننده.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];
$current_user_id = $ctx['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if ($action === 'checkout') {
        $visitor_id = (int) ($_POST['visitor_id'] ?? 0);
        $response = callAPI('PUT', '/visitors/' . $visitor_id . '/checkout', ['status' => 'exited']);
        if (!empty($response['success'])) {
            $alert_message = 'خروج مهمان ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت خروج.';
        }
    } elseif ($action === 'delete') {
        $visitor_id = (int) ($_POST['visitor_id'] ?? 0);
        $response = callAPI('DELETE', '/visitors/' . $visitor_id);
        if (!empty($response['success'])) {
            $alert_message = 'مهمان حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف مهمان.';
        }
    } else {
        $visitor_name = trim($_POST['visitor_name'] ?? '');
        if ($visitor_name === '') {
            $alert_message = 'نام مهمان را وارد کنید.';
            $reopen_modal = $action === 'update' ? 'edit-visitor' : 'add-visitor';
        } else {
            $payload = [
                'visitor_name' => $visitor_name,
                'visitor_car_plate' => trim($_POST['visitor_car_plate'] ?? ''),
                'visit_date' => !empty($_POST['visit_date']) ? $_POST['visit_date'] : null,
                'entry_time' => trim($_POST['entry_time'] ?? ''),
            ];
            if ($action === 'update') {
                $visitor_id = (int) ($_POST['visitor_id'] ?? 0);
                $response = callAPI('PUT', '/visitors/' . $visitor_id, $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'اطلاعات مهمان ویرایش شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش مهمان.';
                    $reopen_modal = 'edit-visitor';
                }
            } else {
                $payload['building_id'] = $building_id;
                $response = callAPI('POST', '/visitors', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'مهمان با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت مهمان.';
                    $reopen_modal = 'add-visitor';
                }
            }
        }
    }
}

// دریافت لیست مهمان‌ها
$visitors = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/visitors', ['building_id' => $building_id]);
    if (!empty($list_response['success'])) {
        $visitors = $list_response['data'] ?? [];
    }
}

$page_title = 'مهمان‌ها';
$header_sub = $building_name ?: 'مدیریت ورود و خروج';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php modal_open_button('add-visitor', 'ثبت مهمان جدید'); ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">مهمان‌های ساختمان (<?= fa_digits(count($visitors)) ?>)</h2>
    </div>

    <?php if (empty($visitors)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">🚶</div>
            مهمانی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($visitors as $visitor): ?>
                <?php
                $v_id = (int) ($visitor['id'] ?? 0);
                $v_name = $visitor['visitor_name'] ?? 'بدون نام';
                $v_plate = $visitor['visitor_car_plate'] ?? '';
                $v_date = $visitor['visit_date'] ?? '';
                $v_time = $visitor['entry_time'] ?? '';
                $exited = ($visitor['status'] ?? '') === 'exited';
                $can_modify = $is_manager || (int) ($visitor['user_id'] ?? 0) === $current_user_id;
                ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($v_name) ?></h3>
                            <?php if ($v_plate !== ''): ?>
                                <p class="text-xs text-gray-500 mt-1" dir="ltr">🚗 <?= htmlspecialchars($v_plate) ?></p>
                            <?php endif; ?>
                            <?php if ($v_date !== '' || $v_time !== ''): ?>
                                <p class="text-xs text-gray-400 mt-1">
                                    <?= fa_digits($v_date) ?><?= $v_time !== '' ? ' • ' . fa_digits($v_time) : '' ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <span class="chip <?= $exited ? 'chip-gray' : 'chip-green' ?>">
                            <?= htmlspecialchars(visitor_status_label($visitor['status'] ?? '')) ?>
                        </span>
                    </div>

                    <?php if ($can_modify): ?>
                        <div class="card-actions">
                            <?php if (!$exited): ?>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="form_action" value="checkout">
                                    <input type="hidden" name="visitor_id" value="<?= $v_id ?>">
                                    <button type="submit" class="btn-chip btn-chip-success">ثبت خروج</button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-visitor"
                                    data-set-visitor_id="<?= $v_id ?>"
                                    data-set-visitor_name="<?= htmlspecialchars($v_name) ?>"
                                    data-set-visitor_car_plate="<?= htmlspecialchars($v_plate) ?>"
                                    data-set-visit_date="<?= htmlspecialchars($v_date) ?>"
                                    data-set-entry_time="<?= htmlspecialchars(substr($v_time, 0, 5)) ?>">
                                ویرایش
                            </button>
                            <form method="POST" action="" data-confirm="این مهمان حذف شود؟" style="display:inline;">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="visitor_id" value="<?= $v_id ?>">
                                <button type="submit" class="btn-chip btn-chip-danger">حذف</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php modal_start('add-visitor', 'ثبت مهمان جدید', 'اطلاعات مهمان و زمان مراجعه'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <input type="hidden" name="form_action" value="create">
        <?php include 'includes/_visitor_form_fields.php'; ?>
        <button type="submit" class="btn-primary">ثبت مهمان</button>
    </form>
<?php modal_end(); ?>

<?php modal_start('edit-visitor', 'ویرایش مهمان', 'اصلاح اطلاعات مهمان'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <input type="hidden" name="form_action" value="update">
        <input type="hidden" name="visitor_id" value="">
        <?php include 'includes/_visitor_form_fields.php'; ?>
        <button type="submit" class="btn-primary">ذخیره تغییرات</button>
    </form>
<?php modal_end(); ?>

<?php require_once 'includes/footer.php'; ?>
