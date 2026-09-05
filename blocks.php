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

// ثبت بلوک جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $name = trim($_POST['name'] ?? '');
    if (empty($name)) {
        $alert_message = 'نام بلوک را وارد کنید.';
    } else {
        $payload = [
            'name' => $name,
            'description' => trim($_POST['description'] ?? ''),
        ];
        $response = callAPI('POST', '/buildings/' . $building_id . '/blocks', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'بلوک با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت بلوک. لطفاً دوباره تلاش کنید.';
        }
    }
}

// دریافت اطلاعات ساختمان و لیست بلوک‌ها
$blocks = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/buildings/' . $building_id . '/blocks');
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $blocks = $list_response['data']['blocks'] ?? [];
    }
}

$page_title = 'مدیریت بلوک‌ها';
$header_sub = $building_name ?: 'ساختار مجتمع';
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
        <span>افزودن بلوک جدید</span>
    </a>

    <!-- لیست بلوک‌ها -->
    <h2 class="section-title">بلوک‌های ساختمان</h2>

    <?php if (empty($blocks)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🏢</div>
            هنوز بلوکی ثبت نشده است.<br>
            با دکمه بالا اولین بلوک را اضافه کنید.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($blocks as $index => $block): ?>
                <div class="card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center flex-shrink-0 font-bold text-lg">
                        <?= fa_digits($index + 1) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-gray-800"><?= htmlspecialchars($block['name'] ?? 'بدون نام') ?></h3>
                        <?php if (!empty($block['description'])): ?>
                            <p class="text-sm text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($block['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="text-xs bg-gray-100 text-gray-500 px-2.5 py-1 rounded-full flex-shrink-0">بلوک</span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- فرم افزودن -->
    <div id="add-form" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
            ثبت بلوک جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="name" class="form-label">نام بلوک *</label>
                <input type="text" id="name" name="name" required class="form-input" placeholder="مثال: بلوک A">
            </div>
            <div>
                <label for="description" class="form-label">توضیحات (اختیاری)</label>
                <textarea id="description" name="description" rows="2" class="form-input" placeholder="مثال: ورودی شمالی"></textarea>
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ذخیره بلوک
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
