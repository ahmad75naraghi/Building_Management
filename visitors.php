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

// ثبت مهمان جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $visitor_name = trim($_POST['visitor_name'] ?? '');
    if ($visitor_name === '') {
        $alert_message = 'نام مهمان را وارد کنید.';
    } else {
        $payload = [
            'building_id' => $building_id,
            'visitor_name' => $visitor_name,
            'visitor_car_plate' => trim($_POST['visitor_car_plate'] ?? ''),
            'visit_date' => !empty($_POST['visit_date']) ? $_POST['visit_date'] : null,
            'entry_time' => trim($_POST['entry_time'] ?? ''),
        ];
        $response = callAPI('POST', '/visitors', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'مهمان با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت مهمان.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'checkout_visitor') {
        $visitor_id = (int) ($_POST['visitor_id'] ?? 0);
        if ($visitor_id > 0) {
            $response = callAPI('PUT', '/visitors/' . $visitor_id . '/checkout', ['status' => 'exited']);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'خروج مهمان ثبت شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت خروج.';
            }
        }
    } elseif ($action === 'delete_visitor') {
        $visitor_id = (int) ($_POST['visitor_id'] ?? 0);
        if ($visitor_id > 0) {
            $response = callAPI('DELETE', '/visitors/' . $visitor_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'مهمان حذف شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف مهمان.';
            }
        }
    }
}

// دریافت لیست مهمان‌ها
$visitors = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/visitors', ['building_id' => $building_id]);
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $visitors = $list_response['data'] ?? [];
    }
}

$page_title = 'مهمان‌ها';
$header_sub = $building_name ?: 'مدیریت ورود و خروج';
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
        <span>ثبت مهمان جدید</span>
    </a>

    <!-- لیست مهمان‌ها -->
    <h2 class="section-title">مهمان‌های ساختمان</h2>

    <?php if (empty($visitors)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🚶</div>
            مهمانی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($visitors as $visitor): ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($visitor['visitor_name'] ?? 'بدون نام') ?></h3>
                            <?php if (!empty($visitor['visitor_car_plate'])): ?>
                                <p class="text-xs text-gray-500 mt-1" dir="ltr">🚗 <?= htmlspecialchars($visitor['visitor_car_plate']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($visitor['visit_date']) || !empty($visitor['entry_time'])): ?>
                                <p class="text-xs text-gray-400 mt-1">
                                    <?= htmlspecialchars($visitor['visit_date'] ?? '') ?> <?= !empty($visitor['entry_time']) ? '• ' . htmlspecialchars($visitor['entry_time']) : '' ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs px-2.5 py-1 rounded-full flex-shrink-0 <?= (($visitor['status'] ?? '') === 'exited') ? 'bg-gray-200 text-gray-600' : 'bg-green-100 text-green-700' ?>">
                            <?= htmlspecialchars(visitor_status_label($visitor['status'] ?? '')) ?>
                        </span>
                    </div>
                    <!-- مدیریت مهمان -->
                    <div class="flex gap-2 mt-3 pt-3 border-t border-gray-100">
                        <?php if (($visitor['status'] ?? '') !== 'exited'): ?>
                            <form method="POST" action="" class="flex-1">
                                <input type="hidden" name="action" value="checkout_visitor">
                                <input type="hidden" name="visitor_id" value="<?= (int) $visitor['id'] ?>">
                                <button type="submit" class="w-full text-xs bg-green-50 hover:bg-green-100 text-green-700 font-bold px-3 py-2 rounded-lg transition-colors">
                                    ثبت خروج
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" action="" data-confirm="این مهمان حذف شود؟">
                            <input type="hidden" name="action" value="delete_visitor">
                            <input type="hidden" name="visitor_id" value="<?= (int) $visitor['id'] ?>">
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
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
            ثبت مهمان جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="visitor_name" class="form-label">نام مهمان *</label>
                <input type="text" id="visitor_name" name="visitor_name" required class="form-input" placeholder="مثال: علی رضایی">
            </div>
            <div>
                <label for="visitor_car_plate" class="form-label">پلاک خودرو (اختیاری)</label>
                <input type="text" id="visitor_car_plate" name="visitor_car_plate" dir="ltr" class="form-input text-left" placeholder="12 ب 345 ایران 11">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="visit_date" class="form-label">تاریخ مراجعه</label>
                    <input type="date" id="visit_date" name="visit_date" class="form-input">
                </div>
                <div>
                    <label for="entry_time" class="form-label">ساعت ورود</label>
                    <input type="time" id="entry_time" name="entry_time" class="form-input">
                </div>
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت مهمان
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
