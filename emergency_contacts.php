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

// ثبت مخاطب اضطراری
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $name = trim($_POST['contact_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if ($name === '' || $phone === '') {
        $alert_message = 'نام و شماره تماس را وارد کنید.';
    } else {
        $payload = [
            'building_id' => $building_id,
            'name' => $name,
            'role' => trim($_POST['contact_role'] ?? ''),
            'phone' => $phone,
            'email' => trim($_POST['email'] ?? ''),
        ];
        $response = callAPI('POST', '/emergency-contacts', $payload);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'مخاطب اضطراری با موفقیت ثبت شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت مخاطب.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action_name = $_POST['action'];

    if ($action_name === 'delete_emergency_contact') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $response = callAPI('DELETE', '/emergency-contacts/' . $item_id);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'حذف با موفقیت انجام شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف.';
            }
        }
    } elseif ($action_name === 'send_emergency_alert') {
        $message = trim($_POST['alert_message'] ?? '');
        $alert_type_value = trim($_POST['alert_type'] ?? 'general');
        if ($message === '') {
            $alert_message = 'متن هشدار را وارد کنید.';
        } else {
            $response = callAPI('POST', '/emergency-alerts', [
                'building_id' => $building_id,
                'alert_type' => $alert_type_value,
                'message' => $message,
            ]);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'هشدار اضطراری برای ساکنین ارسال شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ارسال هشدار.';
            }
        }
    }
}

// دریافت لیست مخاطبین
$contacts = [];
$alerts = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/emergency-contacts', ['building_id' => $building_id]);
    if (isset($list_response['success']) && $list_response['success'] === true) {
        $contacts = $list_response['data'] ?? [];
    }
    $alerts_response = callAPI('GET', '/emergency-alerts', ['building_id' => $building_id]);
    if (isset($alerts_response['success']) && $alerts_response['success'] === true) {
        $alerts = $alerts_response['data'] ?? [];
    }
}

$alert_type_labels = [
    'general' => 'عمومی',
    'fire' => 'آتش‌سوزی',
    'security' => 'امنیتی',
    'medical' => 'اورژانس پزشکی',
    'water' => 'نشت آب',
    'gas' => 'نشت گاز',
    'elevator' => 'آسانسور',
];

$role_labels = [
    'fire' => 'آتش‌نشانی',
    'police' => 'پلیس',
    'medical' => 'اورژانس',
    'gas' => 'گاز',
    'electricity' => 'برق',
    'water' => 'آب',
    'manager' => 'مدیر ساختمان',
];

$page_title = 'مخاطبین اضطراری';
$header_sub = $building_name ?: 'شماره‌های مهم';
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
        <span>افزودن مخاطب</span>
    </a>

    <!-- هشدار اضطراری -->
    <div class="card p-5 mt-4 border border-red-200">
        <h3 class="font-bold text-red-600 mb-2 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            ارسال هشدار اضطراری
        </h3>
        <form method="POST" action="" class="space-y-3" data-confirm="هشدار اضطراری برای همه ساکنین ارسال شود؟">
            <input type="hidden" name="action" value="send_emergency_alert">
            <div>
                <label for="alert_type" class="form-label">نوع هشدار</label>
                <select id="alert_type" name="alert_type" class="form-input">
                    <?php foreach ($alert_type_labels as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="alert_message" class="form-label">متن هشدار *</label>
                <textarea id="alert_message" name="alert_message" rows="2" required class="form-input" placeholder="مثال: نشت گاز در پارکینگ — لطفاً ساختمان را ترک کنید"></textarea>
            </div>
            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white text-sm font-bold py-2.5 rounded-xl transition-all active:scale-[0.98]">
                ارسال هشدار
            </button>
        </form>
    </div>

    <!-- هشدارهای ارسال‌شده -->
    <?php if (!empty($alerts)): ?>
        <h2 class="section-title">هشدارهای اخیر</h2>
        <div class="space-y-3">
            <?php foreach (array_slice($alerts, 0, 5) as $alert): ?>
                <div class="card p-4 border-r-4 border-r-red-400">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[11px] px-2 py-0.5 rounded-full bg-red-50 text-red-700 font-bold">
                            <?= htmlspecialchars($alert_type_labels[$alert['alert_type'] ?? ''] ?? ($alert['alert_type'] ?? 'عمومی')) ?>
                        </span>
                        <span class="text-[11px] text-gray-400"><?= fa_time_ago($alert['created_at'] ?? '') ?></span>
                    </div>
                    <p class="text-sm text-gray-700 mt-2 leading-6"><?= nl2br(htmlspecialchars($alert['message'] ?? '')) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- لیست مخاطبین -->
    <h2 class="section-title">شماره‌های اضطراری</h2>

    <?php if (empty($contacts)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🆘</div>
            مخاطبی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($contacts as $contact): ?>
                <div class="card p-4 flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-red-50 text-red-600 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($contact['contact_name'] ?? 'بدون نام') ?></h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            <?= htmlspecialchars($role_labels[$contact['contact_role'] ?? ''] ?? ($contact['contact_role'] ?? '')) ?>
                        </p>
                    </div>
                    <a href="tel:<?= htmlspecialchars($contact['phone'] ?? '') ?>" class="bg-green-600 text-white text-sm font-bold px-4 py-2 rounded-xl flex-shrink-0" dir="ltr">
                        <?= htmlspecialchars($contact['phone'] ?? '') ?>
                    </a>
                    <form method="POST" action="" data-confirm="این مخاطب اضطراری حذف شود؟">
                        <input type="hidden" name="action" value="delete_emergency_contact">
                        <input type="hidden" name="item_id" value="<?= (int) $contact['id'] ?>">
                        <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 font-bold px-3 py-2 rounded-lg transition-colors flex-shrink-0">
                            حذف
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- فرم افزودن -->
    <div id="add-form" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
            </svg>
            افزودن مخاطب اضطراری
        </h3>
        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="contact_name" class="form-label">نام *</label>
                <input type="text" id="contact_name" name="contact_name" required class="form-input" placeholder="مثال: اورژانس">
            </div>
            <div>
                <label for="contact_role" class="form-label">نقش</label>
                <select id="contact_role" name="contact_role" class="form-input">
                    <option value="medical">اورژانس</option>
                    <option value="fire">آتش‌نشانی</option>
                    <option value="police">پلیس</option>
                    <option value="gas">گاز</option>
                    <option value="electricity">برق</option>
                    <option value="water">آب</option>
                    <option value="manager">مدیر ساختمان</option>
                    <option value="">سایر</option>
                </select>
            </div>
            <div>
                <label for="phone" class="form-label">شماره تماس *</label>
                <input type="tel" id="phone" name="phone" dir="ltr" required class="form-input text-left" placeholder="115">
            </div>
            <div>
                <label for="email" class="form-label">ایمیل (اختیاری)</label>
                <input type="email" id="email" name="email" dir="ltr" class="form-input text-left" placeholder="contact@example.com">
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت مخاطب
            </button>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
