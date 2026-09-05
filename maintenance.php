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

// ثبت درخواست تعمیرات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        $alert_message = 'عنوان مشکل را وارد کنید.';
    } else {
        $payload = [
            'building_id' => $building_id,
            'title' => $title,
            'description' => trim($_POST['description'] ?? ''),
        ];
        $response = callAPI('POST', '/maintenance', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'درخواست تعمیرات با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت درخواست.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_maintenance_status') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if ($request_id > 0 && in_array($status, ['pending', 'in_progress', 'resolved', 'closed'], true)) {
            $response = callAPI('PUT', '/maintenance/' . $request_id . '/status', ['status' => $status]);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'وضعیت درخواست به‌روزرسانی شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در تغییر وضعیت.';
            }
        }
    } elseif ($action === 'delete_maintenance') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        if ($request_id > 0) {
            $response = callAPI('DELETE', '/maintenance/' . $request_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'درخواست حذف شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف درخواست.';
            }
        }
    }
}

// دریافت لیست درخواست‌ها
$requests = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/maintenance', ['building_id' => $building_id]);
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $requests = $list_response['data'] ?? [];
    }
}

$page_title = 'درخواست تعمیرات';
$header_sub = $building_name ?: 'پشتیبانی فنی';
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
        <span>ثبت درخواست جدید</span>
    </a>

    <!-- لیست درخواست‌ها -->
    <h2 class="section-title">درخواست‌های تعمیرات</h2>

    <?php if (empty($requests)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🔧</div>
            درخواست تعمیراتی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($requests as $request): ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-bold text-gray-800 text-sm flex-1"><?= htmlspecialchars($request['title'] ?? 'بدون عنوان') ?></h3>
                        <span class="text-[11px] text-gray-400 flex-shrink-0"><?= fa_time_ago($request['created_at'] ?? '') ?></span>
                    </div>
                    <?php if (!empty($request['description'])): ?>
                        <p class="text-sm text-gray-500 mt-2 leading-6"><?= nl2br(htmlspecialchars($request['description'])) ?></p>
                    <?php endif; ?>
                    <div class="mt-3 flex items-center justify-between gap-2 flex-wrap">
                        <span class="text-[10px] px-2.5 py-1 rounded-full bg-blue-50 text-blue-700">
                            <?= htmlspecialchars(maintenance_status_label($request['status'] ?? 'pending')) ?>
                        </span>
                        <span class="text-[10px] text-gray-400"><?= fa_time_ago($request['updated_at'] ?? $request['created_at'] ?? '') ?></span>
                    </div>
                    <!-- مدیریت درخواست -->
                    <div class="flex gap-2 mt-3 pt-3 border-t border-gray-100">
                        <form method="POST" action="" class="flex items-center gap-2 flex-1">
                            <input type="hidden" name="action" value="update_maintenance_status">
                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                            <select name="status" class="form-input text-xs py-2">
                                <option value="pending" <?= ($request['status'] ?? '') === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                                <option value="in_progress" <?= ($request['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>در حال انجام</option>
                                <option value="resolved" <?= ($request['status'] ?? '') === 'resolved' ? 'selected' : '' ?>>انجام‌شده</option>
                                <option value="closed" <?= ($request['status'] ?? '') === 'closed' ? 'selected' : '' ?>>بسته‌شده</option>
                            </select>
                            <button type="submit" class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-3 py-2 rounded-lg transition-colors flex-shrink-0">
                                ثبت وضعیت
                            </button>
                        </form>
                        <form method="POST" action="" data-confirm="این درخواست تعمیر حذف شود؟">
                            <input type="hidden" name="action" value="delete_maintenance">
                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
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
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" />
            </svg>
            ثبت درخواست تعمیرات
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="title" class="form-label">عنوان مشکل *</label>
                <input type="text" id="title" name="title" required class="form-input" placeholder="مثال: نشتی لوله آب">
            </div>
            <div>
                <label for="description" class="form-label">توضیحات (اختیاری)</label>
                <textarea id="description" name="description" rows="3" class="form-input" placeholder="جزئیات مشکل و محل دقیق آن..."></textarea>
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت درخواست
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
