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

// ثبت جلسه جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        $alert_message = 'عنوان جلسه را وارد کنید.';
    } else {
        $payload = [
            'building_id' => $building_id,
            'title' => $title,
            'description' => trim($_POST['description'] ?? ''),
            'meeting_date' => !empty($_POST['meeting_date']) ? $_POST['meeting_date'] : null,
            'location' => trim($_POST['location'] ?? ''),
        ];
        $response = callAPI('POST', '/meetings', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'جلسه با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت جلسه.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_meeting_status') {
        $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if ($meeting_id > 0 && in_array($status, ['scheduled', 'completed', 'cancelled'], true)) {
            $response = callAPI('PUT', '/meetings/' . $meeting_id . '/status', ['status' => $status]);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'وضعیت جلسه به‌روزرسانی شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در تغییر وضعیت جلسه.';
            }
        }
    } elseif ($action === 'delete_meeting') {
        $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
        if ($meeting_id > 0) {
            $response = callAPI('DELETE', '/meetings/' . $meeting_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'جلسه حذف شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف جلسه.';
            }
        }
    } elseif ($action === 'add_minutes') {
        $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
        $content = trim($_POST['minutes_content'] ?? '');
        if ($meeting_id > 0 && $content !== '') {
            $response = callAPI('POST', '/meetings/' . $meeting_id . '/minutes', ['minutes_content' => $content]);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'صورت‌جلسه ثبت شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت صورت‌جلسه.';
            }
        } else {
            $alert_message = 'متن صورت‌جلسه را وارد کنید.';
        }
    }
}

// دریافت لیست جلسات
$meetings = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/meetings', ['building_id' => $building_id]);
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $meetings = $list_response['data'] ?? [];
    }
}

$page_title = 'جلسات';
$header_sub = $building_name ?: 'جلسات ساختمان';
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
        <span>ثبت جلسه جدید</span>
    </a>

    <!-- لیست جلسات -->
    <h2 class="section-title">جلسات ساختمان</h2>

    <?php if (empty($meetings)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🤝</div>
            جلسه‌ای ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($meetings as $meeting): ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-bold text-gray-800 text-sm flex-1"><?= htmlspecialchars($meeting['title'] ?? 'بدون عنوان') ?></h3>
                        <span class="text-[11px] px-2 py-0.5 rounded-full flex-shrink-0 <?= (($meeting['status'] ?? '') === 'completed') ? 'bg-green-100 text-green-700' : (($meeting['status'] ?? '') === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-blue-50 text-blue-700') ?>">
                            <?= htmlspecialchars(meeting_status_label($meeting['status'] ?? 'scheduled')) ?>
                        </span>
                    </div>
                    <?php if (!empty($meeting['description'])): ?>
                        <p class="text-sm text-gray-500 mt-2 leading-6"><?= nl2br(htmlspecialchars($meeting['description'])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($meeting['meeting_date']) || !empty($meeting['location'])): ?>
                        <p class="text-xs text-gray-400 mt-2">
                            📅 <?= htmlspecialchars($meeting['meeting_date'] ?? '') ?>
                            <?php if (!empty($meeting['location'])): ?> • 📍 <?= htmlspecialchars($meeting['location']) ?><?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <!-- مدیریت جلسه -->
                    <div class="flex gap-2 mt-3 pt-3 border-t border-gray-100">
                        <form method="POST" action="" class="flex items-center gap-2 flex-1">
                            <input type="hidden" name="action" value="update_meeting_status">
                            <input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>">
                            <select name="status" class="form-input text-xs py-2">
                                <option value="scheduled" <?= ($meeting['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>>برنامه‌ریزی شده</option>
                                <option value="completed" <?= ($meeting['status'] ?? '') === 'completed' ? 'selected' : '' ?>>برگزار شده</option>
                                <option value="cancelled" <?= ($meeting['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>لغو شده</option>
                            </select>
                            <button type="submit" class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-3 py-2 rounded-lg transition-colors flex-shrink-0">
                                ثبت وضعیت
                            </button>
                        </form>
                        <form method="POST" action="" data-confirm="این جلسه حذف شود؟">
                            <input type="hidden" name="action" value="delete_meeting">
                            <input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>">
                            <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg transition-colors flex-shrink-0">
                                حذف
                            </button>
                        </form>
                    </div>
                    <!-- صورت‌جلسه -->
                    <form method="POST" action="" class="mt-3 pt-3 border-t border-dashed border-gray-200">
                        <input type="hidden" name="action" value="add_minutes">
                        <input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>">
                        <label class="form-label text-[11px]">صورت‌جلسه</label>
                        <textarea name="minutes_content" rows="2" class="form-input text-sm" placeholder="خلاصه تصمیمات این جلسه..."></textarea>
                        <button type="submit" class="mt-2 text-xs bg-purple-50 hover:bg-purple-100 text-purple-700 font-bold px-3 py-2 rounded-lg transition-colors">
                            ثبت صورت‌جلسه
                        </button>
                    </form>
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
            ثبت جلسه جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="title" class="form-label">عنوان جلسه *</label>
                <input type="text" id="title" name="title" required class="form-input" placeholder="مثال: جلسه مجمع سالیانه">
            </div>
            <div>
                <label for="description" class="form-label">دستور جلسه (اختیاری)</label>
                <textarea id="description" name="description" rows="2" class="form-input" placeholder="موارد دستور جلسه..."></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="meeting_date" class="form-label">تاریخ جلسه</label>
                    <input type="date" id="meeting_date" name="meeting_date" class="form-input">
                </div>
                <div>
                    <label for="location" class="form-label">مکان</label>
                    <input type="text" id="location" name="location" class="form-input" placeholder="مثال: سالن اجتماعات">
                </div>
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت جلسه
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
