<?php
// ثبت ساختمان جدید — با قالب استاندارد اپ
require_once 'includes/api_helper.php';

// اگر کاربر لاگین نیست، به صفحه ورود هدایت شود
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: login.php");
    exit;
}

$alert_message = '';
$alert_type = 'error';

// بررسی ارسال فرم
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $custom_name = trim($_POST['custom_name'] ?? '');

    // اعتبارسنجی — API هر دو فیلد نام و آدرس را اجباری می‌داند
    if ($name === '' || $address === '') {
        $alert_message = 'نام و آدرس ساختمان را وارد کنید.';
    } else {
        $building_data = [
            'name' => $name,
            'address' => $address,
            'custom_name' => $custom_name !== '' ? $custom_name : null,
            'theme_color' => trim($_POST['theme_color'] ?? '#1a73e8'),
        ];

        $response = callAPI('POST', '/buildings', $building_data);

        if (isset($response['success']) && $response['success'] === true) {
            header("Location: index.php");
            exit;
        } else {
            if (isset($response['http_code']) && $response['http_code'] == 401) {
                session_destroy();
                header("Location: login.php");
                exit;
            }
            $alert_message = $response['message'] ?? 'خطا در ثبت ساختمان. لطفاً دوباره تلاش کنید.';
        }
    }
}

$page_title = 'ثبت ساختمان';
$header_sub = 'ایجاد مجتمع جدید';
$back_url = 'index.php';
$nav_active = 'none';
require_once 'includes/header.php';
?>

        <main class="p-5">

            <!-- فرم ثبت ساختمان -->
            <div class="card p-5" style="margin-top: 8px;">
                <h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2" style="font-size: 15px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4" />
                    </svg>
                    اطلاعات ساختمان
                </h3>
                <p class="text-xs text-gray-500 mb-4">فیلدهای ستاره‌دار اجباری هستند.</p>

                <form method="POST" action="" class="space-y-4" data-loading>
                    <div>
                        <label for="name" class="form-label">نام ساختمان *</label>
                        <input type="text" id="name" name="name" required class="form-input"
                               placeholder="مثال: مجتمع رویال فالنیک"
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="address" class="form-label">آدرس *</label>
                        <input type="text" id="address" name="address" required class="form-input"
                               placeholder="مثال: تهران، خیابان اصلی، پلاک ۱۲"
                               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="custom_name" class="form-label">نام نمایشی (اختیاری)</label>
                        <input type="text" id="custom_name" name="custom_name" class="form-input"
                               placeholder="مثال: برج آبی">
                    </div>
                    <div>
                        <label for="theme_color" class="form-label">رنگ تم (اختیاری)</label>
                        <div class="flex items-center gap-3">
                            <input type="color" id="theme_color" name="theme_color" class="form-input" style="width: 56px; height: 44px; padding: 4px;" value="#1a73e8">
                            <span class="text-xs text-gray-400">رنگ آیکون ساختمان در داشبورد</span>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 8px;">
                            <path d="M5 13l4 4L19 7" />
                        </svg>
                        ثبت ساختمان
                    </button>
                </form>
            </div>

        </main>

<?php require_once 'includes/footer.php'; ?>
