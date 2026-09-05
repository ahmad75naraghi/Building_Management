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

// دریافت لیست بلوک‌ها (برای انتخاب در فرم)
$blocks = [];
if ($building_id > 0) {
    $blocks_response = callAPI('GET', '/buildings/' . $building_id . '/blocks');
    if (isset($blocks_response['success']) && $blocks_response['success'] === true) {
        $blocks = $blocks_response['data']['blocks'] ?? [];
    }
}

// ثبت طبقه جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $floor_number = trim($_POST['floor_number'] ?? '');
    if ($floor_number === '') {
        $alert_message = 'شماره طبقه را وارد کنید.';
    } else {
        $payload = [
            'floor_number' => $floor_number,
            'name' => trim($_POST['name'] ?? ''),
            'block_id' => !empty($_POST['block_id']) ? (int) $_POST['block_id'] : null,
        ];
        $response = callAPI('POST', '/buildings/' . $building_id . '/floors', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'طبقه با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت طبقه. لطفاً دوباره تلاش کنید.';
        }
    }
}

// دریافت اطلاعات ساختمان و لیست طبقات
$floors = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/buildings/' . $building_id . '/floors');
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $floors = $list_response['data']['floors'] ?? [];
    }
}

// نام بلوک‌ها برای نمایش
$block_names = [];
foreach ($blocks as $b) {
    $block_names[$b['id']] = $b['name'];
}

$page_title = 'مدیریت طبقات';
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
        <span>افزودن طبقه جدید</span>
    </a>

    <!-- لیست طبقات -->
    <h2 class="section-title">طبقات ساختمان</h2>

    <?php if (empty($floors)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🏗️</div>
            هنوز طبقه‌ای ثبت نشده است.<br>
            با دکمه بالا اولین طبقه را اضافه کنید.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($floors as $floor): ?>
                <div class="card p-4 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-gray-800">طبقه <?= fa_digits($floor['floor_number'] ?? '—') ?></h3>
                        <p class="text-sm text-gray-500 mt-0.5 truncate">
                            <?= htmlspecialchars($floor['name'] ?? '') ?>
                            <?php if (!empty($floor['block_id']) && isset($block_names[$floor['block_id']])): ?>
                                • بلوک <?= htmlspecialchars($block_names[$floor['block_id']]) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <span class="text-xs bg-gray-100 text-gray-500 px-2.5 py-1 rounded-full flex-shrink-0">طبقه</span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- فرم افزودن -->
    <div id="add-form" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
            </svg>
            ثبت طبقه جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="floor_number" class="form-label">شماره طبقه *</label>
                <input type="text" id="floor_number" name="floor_number" required class="form-input" placeholder="مثال: ۱ یا همکف">
            </div>
            <div>
                <label for="name" class="form-label">نام طبقه (اختیاری)</label>
                <input type="text" id="name" name="name" class="form-input" placeholder="مثال: طبقه اول">
            </div>
            <?php if (!empty($blocks)): ?>
            <div>
                <label for="block_id" class="form-label">بلوک (اختیاری)</label>
                <select id="block_id" name="block_id" class="form-input">
                    <option value="">— بدون بلوک —</option>
                    <?php foreach ($blocks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ذخیره طبقه
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
