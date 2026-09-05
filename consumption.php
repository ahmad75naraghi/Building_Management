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

// دریافت لیست واحدها (برای انتخاب در فرم)
$units = [];
if ($building_id > 0) {
    $units_response = callAPI('GET', '/buildings/' . $building_id . '/units');
    if (isset($units_response['success']) && $units_response['success'] === true) {
        $units = $units_response['data']['units'] ?? [];
    }
}

// ثبت قرائت کنتور
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $amount = trim($_POST['reading_value'] ?? '');
    if ($amount === '') {
        $alert_message = 'مقدار قرائت را وارد کنید.';
    } else {
        $payload = [
            'building_id' => $building_id,
            'unit_id' => !empty($_POST['unit_id']) ? (int) $_POST['unit_id'] : null,
            'consumption_type' => $_POST['consumption_type'] ?? 'water',
            'reading_value' => (float) $amount,
            'reading_date' => !empty($_POST['reading_date']) ? $_POST['reading_date'] : null,
            'notes' => trim($_POST['notes'] ?? ''),
        ];
        $response = callAPI('POST', '/consumption', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'قرائت کنتور با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت قرائت.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action_name = $_POST['action'];

    if ($action_name === 'delete_consumption') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $response = callAPI('DELETE', '/consumption/' . $item_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'حذف با موفقیت انجام شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف.';
            }
        }
    }
}

// دریافت لیست قرائت‌ها
$readings = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/consumption', ['building_id' => $building_id]);
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $readings = $list_response['data'] ?? [];
    }
}

$unit_labels = [];
foreach ($units as $u) {
    $unit_labels[$u['id']] = 'واحد ' . ($u['unit_number'] ?? $u['id']);
}

$page_title = 'مصرف انرژی';
$header_sub = $building_name ?: 'قرائت کنتور';
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
        <span>ثبت قرائت جدید</span>
    </a>

    <!-- لیست قرائت‌ها -->
    <h2 class="section-title">قرائت‌های ثبت‌شده</h2>

    <?php if (empty($readings)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">⚡</div>
            قرائتی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($readings as $reading): ?>
                <div class="card p-4">
                    <div class="flex items-center gap-4">
                        <div class="w-11 h-11 rounded-xl bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm">
                                <?= htmlspecialchars(consumption_type_label($reading['consumption_type'] ?? '')) ?>
                                <span class="text-gray-400 font-normal">— <?= htmlspecialchars($unit_labels[$reading['unit_id'] ?? ''] ?? ('واحد ' . ($reading['unit_id'] ?? '—'))) ?></span>
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                مقدار: <?= fa_number($reading['reading_value'] ?? 0) ?>
                                <?php if (!empty($reading['reading_date'])): ?>
                                    • <?= htmlspecialchars($reading['reading_date']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <form method="POST" action="" data-confirm="این قرائت حذف شود؟">
                            <input type="hidden" name="action" value="delete_consumption">
                            <input type="hidden" name="item_id" value="<?= (int) $reading['id'] ?>">
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
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            ثبت قرائت کنتور
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="consumption_type" class="form-label">نوع مصرف</label>
                    <select id="consumption_type" name="consumption_type" class="form-input">
                        <option value="water">آب</option>
                        <option value="electricity">برق</option>
                        <option value="gas">گاز</option>
                    </select>
                </div>
                <div>
                    <label for="unit_id" class="form-label">واحد</label>
                    <select id="unit_id" name="unit_id" class="form-input">
                        <option value="">— بدون واحد —</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($unit_labels[$u['id']]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label for="reading_value" class="form-label">مقدار قرائت *</label>
                <input type="number" id="reading_value" name="reading_value" required min="0" step="0.1" class="form-input" placeholder="مثال: 150">
            </div>
            <div>
                <label for="reading_date" class="form-label">تاریخ قرائت</label>
                <input type="date" id="reading_date" name="reading_date" class="form-input">
            </div>
            <div>
                <label for="notes" class="form-label">یادداشت (اختیاری)</label>
                <textarea id="notes" name="notes" rows="2" class="form-input" placeholder="توضیح اضافه..."></textarea>
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت قرائت
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
