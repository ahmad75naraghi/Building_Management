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

// ثبت مشاع جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $alert_message = 'نام مشاع را وارد کنید.';
    } else {
        $payload = [
            'name' => $name,
            'type' => trim($_POST['type'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'bookable' => isset($_POST['bookable']) ? 1 : 0,
        ];
        $response = callAPI('POST', '/buildings/' . $building_id . '/common-areas', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'مشاع با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت مشاع. لطفاً دوباره تلاش کنید.';
        }
    }
}

// دریافت اطلاعات ساختمان و لیست مشاعات
$common_areas = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/buildings/' . $building_id . '/common-areas');
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $common_areas = $list_response['data']['common_areas'] ?? [];
    }
}

$page_title = 'مدیریت مشاعات';
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
        <span>افزودن مشاع جدید</span>
    </a>

    <!-- لیست مشاعات -->
    <h2 class="section-title">مشاعات ساختمان</h2>

    <?php if (empty($common_areas)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🎯</div>
            هنوز مشاعی ثبت نشده است.<br>
            با دکمه بالا اولین مشاع را اضافه کنید.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($common_areas as $area): ?>
                <div class="card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-gray-800"><?= htmlspecialchars($area['name'] ?? 'بدون نام') ?></h3>
                        <p class="text-sm text-gray-500 mt-0.5 truncate">
                            <?= htmlspecialchars($area['description'] ?? '') ?>
                            <?php if (!empty($area['type'])): ?>
                                <span class="text-xs bg-purple-50 text-purple-700 px-2 py-0.5 rounded-full inline-block ml-1"><?= htmlspecialchars($area['type']) ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if (!empty($area['bookable']) && (int) $area['bookable'] === 1): ?>
                        <span class="text-xs bg-green-100 text-green-700 px-2.5 py-1 rounded-full flex-shrink-0">قابل رزرو</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- فرم افزودن -->
    <div id="add-form" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
            </svg>
            ثبت مشاع جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="name" class="form-label">نام مشاع *</label>
                <input type="text" id="name" name="name" required class="form-input" placeholder="مثال: سالن اجتماعات">
            </div>
            <div>
                <label for="type" class="form-label">نوع (اختیاری)</label>
                <input type="text" id="type" name="type" class="form-input" placeholder="مثال: سالن، استخر، پارکینگ">
            </div>
            <div>
                <label for="description" class="form-label">توضیحات (اختیاری)</label>
                <textarea id="description" name="description" rows="2" class="form-input" placeholder="توضیح کوتاه درباره مشاع"></textarea>
            </div>
            <label class="flex items-center gap-3 cursor-pointer bg-gray-50 border border-gray-200 rounded-xl p-3.5">
                <input type="checkbox" id="bookable" name="bookable" value="1" class="h-5 w-5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <span class="text-sm font-medium text-gray-700">قابل رزرو توسط ساکنین (رزرو مشاعات)</span>
            </label>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ذخیره مشاع
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
