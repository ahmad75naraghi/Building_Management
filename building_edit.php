<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: login.php");
    exit;
}

$building_id = (int) ($_GET['id'] ?? 0);

$alert_message = '';
$alert_type = 'error';
$building = null;

// دریافت اطلاعات ساختمان
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building = $building_response['data'] ?? null;
    }
}

if (!$building) {
    $page_title = 'ویرایش ساختمان';
    $header_sub = 'ساختمان یافت نشد';
    $back_url = 'index.php';
    $active_nav = 'home';
    require_once 'includes/page_head.php';
    echo '<main class="p-5"><div class="card empty-state"><div class="text-4xl mb-3">🏢</div>ساختمان موردنظر یافت نشد.</div></main>';
    require_once 'includes/page_tail.php';
    exit;
}

// ذخیره تغییرات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    if ($name === '' || $address === '') {
        $alert_message = 'نام و آدرس ساختمان الزامی است.';
    } else {
        $payload = [
            'name' => $name,
            'address' => $address,
            'custom_name' => trim($_POST['custom_name'] ?? ''),
            'theme_color' => trim($_POST['theme_color'] ?? ''),
        ];
        $response = callAPI('PUT', '/buildings/' . $building_id, $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'اطلاعات ساختمان با موفقیت به‌روزرسانی شد.';
            $alert_type = 'success';
            $building['name'] = $name;
            $building['address'] = $address;
            $building['custom_name'] = $payload['custom_name'];
            $building['theme_color'] = $payload['theme_color'];
        } else {
            $alert_message = $response['message'] ?? 'خطا در ذخیره تغییرات.';
        }
    }
}

$page_title = 'ویرایش ساختمان';
$header_sub = $building['name'] ?? 'ویرایش اطلاعات';
$back_url = 'building_view.php?id=' . $building_id;
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <div class="card p-5">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            ویرایش اطلاعات ساختمان
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="name" class="form-label">نام ساختمان *</label>
                <input type="text" id="name" name="name" required class="form-input" value="<?= htmlspecialchars($building['name'] ?? '') ?>" placeholder="مثال: برج آسمان">
            </div>
            <div>
                <label for="address" class="form-label">آدرس *</label>
                <textarea id="address" name="address" rows="2" required class="form-input" placeholder="آدرس کامل"><?= htmlspecialchars($building['address'] ?? '') ?></textarea>
            </div>
            <div>
                <label for="custom_name" class="form-label">نام سفارشی (اختیاری)</label>
                <input type="text" id="custom_name" name="custom_name" class="form-input" value="<?= htmlspecialchars($building['custom_name'] ?? '') ?>" placeholder="مثلاً: برج آسمان — بلوک A">
            </div>
            <div>
                <label for="theme_color" class="form-label">رنگ تم (اختیاری)</label>
                <input type="color" id="theme_color" name="theme_color" class="form-input h-12 p-1" value="<?= htmlspecialchars($building['theme_color'] ?? '#1a73e8') ?>">
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    ذخیره تغییرات
                </button>
                <a href="building_view.php?id=<?= $building_id ?>" class="btn-secondary flex-1 text-center">انصراف</a>
            </div>
        </form>
    </div>

    <!-- حذف ساختمان -->
    <div class="card p-5 mt-6 border border-red-200">
        <h3 class="font-bold text-red-600 mb-2">حذف ساختمان</h3>
        <p class="text-xs text-gray-500 mb-4">با حذف ساختمان، دسترسی شما به آن برای همیشه از بین می‌رود. این عملیات قابل بازگشت نیست.</p>
        <form method="POST" action="building_delete.php" data-confirm="آیا مطمئن هستید؟ این ساختمان و تمام داده‌های آن حذف می‌شود.">
            <input type="hidden" name="id" value="<?= $building_id ?>">
            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white text-sm font-bold py-2.5 rounded-xl transition-all active:scale-[0.98]">
                حذف ساختمان
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
