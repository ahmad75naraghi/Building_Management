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

// ثبت اطلاعیه جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    if ($title === '' || $content === '') {
        $alert_message = 'عنوان و متن اطلاعیه را وارد کنید.';
    } else {
        $payload = [
            'building_id' => $building_id,
            'title' => $title,
            'content' => $content,
            'is_pinned' => isset($_POST['is_pinned']) ? true : false,
        ];
        $response = callAPI('POST', '/announcements', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'اطلاعیه با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت اطلاعیه.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action_name = $_POST['action'];

    if ($action_name === 'delete_announcement') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $response = callAPI('DELETE', '/announcements/' . $item_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'حذف با موفقیت انجام شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف.';
            }
        }
    }
}

// دریافت لیست اطلاعیه‌ها
$announcements = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/announcements', ['building_id' => $building_id]);
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $announcements = $list_response['data'] ?? [];
    }
}

$page_title = 'اطلاعیه‌ها';
$header_sub = $building_name ?: 'اعلان‌های ساختمان';
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
        <span>ثبت اطلاعیه جدید</span>
    </a>

    <!-- لیست اطلاعیه‌ها -->
    <h2 class="section-title">آخرین اطلاعیه‌ها</h2>

    <?php if (empty($announcements)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">📢</div>
            هنوز اطلاعیه‌ای ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($announcements as $announcement): ?>
                <div class="card p-4 <?= !empty($announcement['is_pinned']) ? 'border-r-4 border-r-blue-600' : '' ?>">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-bold text-gray-800 text-sm flex-1">
                            <?php if (!empty($announcement['is_pinned'])): ?>
                                <span class="text-blue-600 ml-1">📌</span>
                            <?php endif; ?>
                            <?= htmlspecialchars($announcement['title'] ?? 'بدون عنوان') ?>
                        </h3>
                        <span class="text-[11px] text-gray-400 flex-shrink-0"><?= fa_time_ago($announcement['created_at'] ?? '') ?></span>
                    </div>
                    <?php if (!empty($announcement['content'])): ?>
                        <p class="text-sm text-gray-500 mt-2 leading-6"><?= nl2br(htmlspecialchars($announcement['content'])) ?></p>
                    <?php endif; ?>
                    <form method="POST" action="" class="mt-3 pt-3 border-t border-gray-100" data-confirm="این اطلاعیه حذف شود؟">
                        <input type="hidden" name="action" value="delete_announcement">
                        <input type="hidden" name="item_id" value="<?= (int) $announcement['id'] ?>">
                        <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg transition-colors">
                            حذف اطلاعیه
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
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
            </svg>
            ثبت اطلاعیه جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="title" class="form-label">عنوان *</label>
                <input type="text" id="title" name="title" required class="form-input" placeholder="مثال: قطعی آب">
            </div>
            <div>
                <label for="content" class="form-label">متن اطلاعیه *</label>
                <textarea id="content" name="content" rows="3" required class="form-input" placeholder="متن کامل اطلاعیه را بنویسید..."></textarea>
            </div>
            <label class="flex items-center gap-3 cursor-pointer bg-gray-50 border border-gray-200 rounded-xl p-3.5">
                <input type="checkbox" id="is_pinned" name="is_pinned" value="1" class="h-5 w-5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <span class="text-sm font-medium text-gray-700">پین شود (نمایش در ابتدای لیست)</span>
            </label>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت اطلاعیه
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
