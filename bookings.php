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

// دریافت لیست مشاعات (برای انتخاب در فرم)
$common_areas = [];
if ($building_id > 0) {
    $areas_response = callAPI('GET', '/buildings/' . $building_id . '/common-areas');
    if (isset($areas_response['success']) && $areas_response['success'] === true) {
        $common_areas = $areas_response['data']['common_areas'] ?? [];
    }
}

// ثبت رزرو جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $common_area_id = (int) ($_POST['common_area_id'] ?? 0);
    $booking_date = trim($_POST['booking_date'] ?? '');
    if ($common_area_id <= 0 || $booking_date === '') {
        $alert_message = 'مشاع و تاریخ رزرو را انتخاب کنید.';
    } else {
        $payload = [
            'building_id' => $building_id,
            'common_area_id' => $common_area_id,
            'booking_date' => $booking_date,
            'start_time' => trim($_POST['start_time'] ?? ''),
            'end_time' => trim($_POST['end_time'] ?? ''),
        ];
        $response = callAPI('POST', '/bookings', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'رزرو با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت رزرو.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_booking_status') {
        $booking_id = (int) ($_POST['booking_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if ($booking_id > 0 && in_array($status, ['pending', 'confirmed', 'cancelled', 'completed'], true)) {
            $response = callAPI('PUT', '/bookings/' . $booking_id . '/status', ['status' => $status]);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'وضعیت رزرو به‌روزرسانی شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در تغییر وضعیت رزرو.';
            }
        }
    } elseif ($action === 'delete_booking') {
        $booking_id = (int) ($_POST['booking_id'] ?? 0);
        if ($booking_id > 0) {
            $response = callAPI('DELETE', '/bookings/' . $booking_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'رزرو حذف شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف رزرو.';
            }
        }
    }
}

// دریافت لیست رزروها
$bookings = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/bookings', ['building_id' => $building_id]);
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $bookings = $list_response['data'] ?? [];
    }
}

$area_names = [];
foreach ($common_areas as $area) {
    $area_names[$area['id']] = $area['name'];
}

$page_title = 'رزرو مشاعات';
$header_sub = $building_name ?: 'سالن، استخر و...';
$back_url = 'building_view.php?id=' . $building_id;
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <!-- دکمه افزودن -->
    <a href="#add-form"
       class="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-4 rounded-2xl shadow-lg shadow-blue-600/25 transition-all active:scale-[0.98]">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        <span>رزرو جدید</span>
    </a>

    <!-- لیست رزروها -->
    <h2 class="section-title">رزروهای ثبت‌شده</h2>

    <?php if (empty($bookings)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">📅</div>
            رزروی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($bookings as $booking): ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm">
                                <?= htmlspecialchars($area_names[$booking['common_area_id'] ?? ''] ?? 'مشاع') ?>
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                <?php if (!empty($booking['booking_date'])): ?>
                                    📅 <?= htmlspecialchars($booking['booking_date']) ?>
                                <?php endif; ?>
                                <?php if (!empty($booking['start_time'])): ?>
                                    • 🕐 <?= htmlspecialchars($booking['start_time']) ?><?= !empty($booking['end_time']) ? ' تا ' . htmlspecialchars($booking['end_time']) : '' ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="text-xs px-2.5 py-1 rounded-full flex-shrink-0 <?= (($booking['status'] ?? '') === 'confirmed') ? 'bg-green-100 text-green-700' : 'bg-blue-50 text-blue-700' ?>">
                            <?= htmlspecialchars(booking_status_label($booking['status'] ?? '')) ?>
                        </span>
                    </div>
                    <!-- مدیریت رزرو -->
                    <div class="flex gap-2 mt-3 pt-3 border-t border-gray-100">
                        <form method="POST" action="" class="flex items-center gap-2 flex-1">
                            <input type="hidden" name="action" value="update_booking_status">
                            <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <select name="status" class="form-input text-xs py-2">
                                <option value="pending" <?= ($booking['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار تأیید</option>
                                <option value="confirmed" <?= ($booking['status'] ?? '') === 'confirmed' ? 'selected' : '' ?>>تأیید شده</option>
                                <option value="cancelled" <?= ($booking['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>لغو شده</option>
                                <option value="completed" <?= ($booking['status'] ?? '') === 'completed' ? 'selected' : '' ?>>انجام شده</option>
                            </select>
                            <button type="submit" class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-3 py-2 rounded-lg transition-colors flex-shrink-0">
                                ثبت وضعیت
                            </button>
                        </form>
                        <form method="POST" action="" data-confirm="این رزرو حذف شود؟">
                            <input type="hidden" name="action" value="delete_booking">
                            <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg transition-colors flex-shrink-0">
                                حذف
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- فرم افزودن -->
    <div id="add-form" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            ثبت رزرو جدید
        </h3>
        <?php if (empty($common_areas)): ?>
            <div class="bg-amber-50 text-amber-700 border border-amber-200 rounded-xl p-4 text-sm mb-4">
                ابتدا از صفحه «مشاعات» یک فضای قابل رزرو (مثل سالن اجتماعات) ایجاد کنید.
            </div>
        <?php endif; ?>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="common_area_id" class="form-label">مشاع *</label>
                <select id="common_area_id" name="common_area_id" required class="form-input">
                    <option value="">— انتخاب مشاع —</option>
                    <?php foreach ($common_areas as $area): ?>
                        <option value="<?= (int) $area['id'] ?>"><?= htmlspecialchars($area['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="booking_date" class="form-label">تاریخ رزرو *</label>
                <input type="date" id="booking_date" name="booking_date" required class="form-input">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="start_time" class="form-label">از ساعت</label>
                    <input type="time" id="start_time" name="start_time" class="form-input">
                </div>
                <div>
                    <label for="end_time" class="form-label">تا ساعت</label>
                    <input type="time" id="end_time" name="end_time" class="form-input">
                </div>
            </div>
            <button type="submit" class="btn-primary" <?= empty($common_areas) ? 'disabled style="opacity:.5; cursor:not-allowed;"' : '' ?>>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت رزرو
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
