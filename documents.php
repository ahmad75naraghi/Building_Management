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

// ثبت سند جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $title = trim($_POST['title'] ?? '');
    $file_path = trim($_POST['file_path'] ?? '');
    if ($title === '' || $file_path === '') {
        $alert_message = 'عنوان و آدرس فایل را وارد کنید.';
    } else {
        $payload = [
            'building_id' => $building_id,
            'title' => $title,
            'file_path' => $file_path,
            'document_type' => trim($_POST['document_type'] ?? ''),
        ];
        $response = callAPI('POST', '/documents', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'سند با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت سند.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action_name = $_POST['action'];

    if ($action_name === 'delete_document') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $response = callAPI('DELETE', '/documents/' . $item_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'حذف با موفقیت انجام شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف.';
            }
        }
    }
}

// دریافت لیست اسناد
$documents = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/documents', ['building_id' => $building_id]);
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $documents = $list_response['data'] ?? [];
    }
}

$page_title = 'اسناد ساختمان';
$header_sub = $building_name ?: 'آرشیو اسناد';
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
        <span>افزودن سند جدید</span>
    </a>

    <!-- لیست اسناد -->
    <h2 class="section-title">اسناد و مدارک</h2>

    <?php if (empty($documents)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">📄</div>
            سندی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($documents as $document): ?>
                <div class="card p-4">
                    <div class="flex items-center gap-4">
                        <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($document['title'] ?? 'بدون عنوان') ?></h3>
                            <?php if (!empty($document['document_type'])): ?>
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 inline-block mt-1"><?= htmlspecialchars($document['document_type']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($document['file_path'])): ?>
                            <a href="<?= htmlspecialchars($document['file_path']) ?>" target="_blank" class="text-blue-600 text-sm font-bold flex-shrink-0">دانلود</a>
                        <?php endif; ?>
                        <form method="POST" action="" data-confirm="این سند حذف شود؟">
                            <input type="hidden" name="action" value="delete_document">
                            <input type="hidden" name="item_id" value="<?= (int) $document['id'] ?>">
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
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            افزودن سند جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="title" class="form-label">عنوان سند *</label>
                <input type="text" id="title" name="title" required class="form-input" placeholder="مثال: اساسنامه ساختمان">
            </div>
            <div>
                <label for="document_type" class="form-label">نوع سند (اختیاری)</label>
                <input type="text" id="document_type" name="document_type" class="form-input" placeholder="مثال: قرارداد، صورت‌جلسه، بیمه">
            </div>
            <div>
                <label for="file_path" class="form-label">آدرس فایل (لینک) *</label>
                <input type="url" id="file_path" name="file_path" dir="ltr" required class="form-input text-left" placeholder="https://example.com/file.pdf">
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت سند
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
