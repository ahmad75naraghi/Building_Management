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

// هر عضو می‌تواند رزرو کند؛ تأیید/رد رزرو فقط با مدیر است.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];
$current_user_id = $ctx['user_id'];

// دریافت لیست مشاعات (برای انتخاب در فرم)
$common_areas = [];
if ($building_id > 0) {
    $areas_response = callAPI('GET', '/buildings/' . $building_id . '/common-areas');
    if (!empty($areas_response['success'])) {
        $common_areas = $areas_response['data']['common_areas'] ?? [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if ($action === 'update_status') {
        if (!$is_manager) {
            $alert_message = 'تأیید یا رد رزرو فقط توسط مدیر ساختمان انجام می‌شود.';
        } else {
            $booking_id = (int) ($_POST['booking_id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if ($booking_id > 0 && in_array($status, ['pending', 'confirmed', 'cancelled', 'completed'], true)) {
                $response = callAPI('PUT', '/bookings/' . $booking_id . '/status', ['status' => $status]);
                if (!empty($response['success'])) {
                    $alert_message = 'وضعیت رزرو به‌روزرسانی شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در تغییر وضعیت رزرو.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $booking_id = (int) ($_POST['booking_id'] ?? 0);
        $response = callAPI('DELETE', '/bookings/' . $booking_id);
        if (!empty($response['success'])) {
            $alert_message = 'رزرو حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف رزرو.';
        }
    } else {
        $common_area_id = (int) ($_POST['common_area_id'] ?? 0);
        $booking_date = trim($_POST['booking_date'] ?? '');
        if ($common_area_id <= 0 || $booking_date === '') {
            $alert_message = 'مشاع و تاریخ رزرو را انتخاب کنید.';
            $reopen_modal = $action === 'update' ? 'edit-booking' : 'add-booking';
        } else {
            $payload = [
                'common_area_id' => $common_area_id,
                'booking_date' => $booking_date,
                'start_time' => trim($_POST['start_time'] ?? ''),
                'end_time' => trim($_POST['end_time'] ?? ''),
            ];
            if ($action === 'update') {
                $booking_id = (int) ($_POST['booking_id'] ?? 0);
                $response = callAPI('PUT', '/bookings/' . $booking_id, $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'رزرو ویرایش شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش رزرو.';
                    $reopen_modal = 'edit-booking';
                }
            } else {
                $payload['building_id'] = $building_id;
                $response = callAPI('POST', '/bookings', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'رزرو با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت رزرو.';
                    $reopen_modal = 'add-booking';
                }
            }
        }
    }
}

// دریافت لیست رزروها
$bookings = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/bookings', ['building_id' => $building_id]);
    if (!empty($list_response['success'])) {
        $bookings = $list_response['data'] ?? [];
    }
}

$area_names = [];
foreach ($common_areas as $area) {
    $area_names[$area['id']] = $area['name'];
}

$status_chips = [
    'pending' => 'chip-amber',
    'confirmed' => 'chip-green',
    'cancelled' => 'chip-red',
    'completed' => 'chip-gray',
];

$page_title = 'رزرو مشاعات';
$header_sub = $building_name ?: 'سالن، استخر و...';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if (empty($common_areas)): ?>
        <div class="hint-card">
            ⚠️ ابتدا از صفحه «مشاعات» یک فضای قابل رزرو (مثل سالن اجتماعات) ایجاد کنید.
        </div>
    <?php else: ?>
        <?php modal_open_button('add-booking', 'رزرو جدید'); ?>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">رزروهای ثبت‌شده (<?= fa_digits(count($bookings)) ?>)</h2>
    </div>

    <?php if (empty($bookings)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">📅</div>
            رزروی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($bookings as $booking): ?>
                <?php
                $b_id = (int) ($booking['id'] ?? 0);
                $b_area = (int) ($booking['common_area_id'] ?? 0);
                $b_date = $booking['booking_date'] ?? '';
                $b_start = $booking['start_time'] ?? '';
                $b_end = $booking['end_time'] ?? '';
                $b_status = $booking['status'] ?? 'pending';
                $is_mine = (int) ($booking['user_id'] ?? 0) === $current_user_id;
                $can_modify = $is_manager || $is_mine;
                ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm">
                                <?= htmlspecialchars($area_names[$b_area] ?? 'مشاع') ?>
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                <?php if ($b_date !== ''): ?>📅 <?= fa_date($b_date) ?><?php endif; ?>
                                <?php if ($b_start !== ''): ?>
                                    • 🕐 <?= fa_digits(substr($b_start, 0, 5)) ?><?= $b_end !== '' ? ' تا ' . fa_digits(substr($b_end, 0, 5)) : '' ?>
                                <?php endif; ?>
                            </p>
                            <?php if (!$is_mine && !empty($booking['user_name'])): ?>
                                <p class="text-[11px] text-gray-400 mt-1">رزروکننده: <?= htmlspecialchars($booking['user_name']) ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="chip <?= $status_chips[$b_status] ?? 'chip-gray' ?>">
                            <?= htmlspecialchars(booking_status_label($b_status)) ?>
                        </span>
                    </div>

                    <?php if ($can_modify): ?>
                        <div class="card-actions" style="flex-wrap:wrap;">
                            <?php if ($is_mine): ?>
                                <button type="button" class="btn-chip btn-chip-edit"
                                        data-modal-open="edit-booking"
                                        data-set-booking_id="<?= $b_id ?>"
                                        data-set-common_area_id="<?= $b_area ?>"
                                        data-set-booking_date="<?= htmlspecialchars($b_date) ?>"
                                        data-set-start_time="<?= htmlspecialchars(substr($b_start, 0, 5)) ?>"
                                        data-set-end_time="<?= htmlspecialchars(substr($b_end, 0, 5)) ?>">
                                    ویرایش
                                </button>
                            <?php endif; ?>

                            <?php if ($is_manager): ?>
                                <form method="POST" action="" style="display:flex;gap:6px;align-items:center;flex:1;min-width:180px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form_action" value="update_status">
                                    <input type="hidden" name="booking_id" value="<?= $b_id ?>">
                                    <select name="status" class="form-input" style="padding:7px 10px;font-size:11.5px;flex:1;">
                                        <option value="pending" <?= $b_status === 'pending' ? 'selected' : '' ?>>در انتظار تأیید</option>
                                        <option value="confirmed" <?= $b_status === 'confirmed' ? 'selected' : '' ?>>تأیید شده</option>
                                        <option value="cancelled" <?= $b_status === 'cancelled' ? 'selected' : '' ?>>لغو شده</option>
                                        <option value="completed" <?= $b_status === 'completed' ? 'selected' : '' ?>>انجام شده</option>
                                    </select>
                                    <button type="submit" class="btn-chip btn-chip-neutral">ثبت</button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" action="" data-confirm="این رزرو حذف شود؟" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="booking_id" value="<?= $b_id ?>">
                                <button type="submit" class="btn-chip btn-chip-danger">حذف</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php if (!empty($common_areas)): ?>
    <?php modal_start('add-booking', 'ثبت رزرو جدید', 'فضای مشاع، تاریخ و ساعت'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">
            <?php include 'includes/_booking_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ثبت رزرو</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-booking', 'ویرایش رزرو', 'اصلاح تاریخ یا ساعت رزرو'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="booking_id" value="">
            <?php include 'includes/_booking_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
