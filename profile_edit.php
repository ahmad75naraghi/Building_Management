<?php
// ویرایش مشخصات کاربری — با قالب استاندارد اپ
require_once 'includes/api_helper.php';

// اگر کاربر لاگین نیست، به صفحه ورود هدایت شود
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

// دریافت اطلاعات فعلی کاربر از API
$me_response = callAPI('GET', '/auth/me');
$user_name = $me_response['data']['name'] ?? ($_SESSION['user_name'] ?? '');
$user_email = $me_response['data']['email'] ?? '';
$user_phone = $me_response['data']['phone'] ?? '';

$alert_message = '';
$alert_type = 'error';

// ذخیره مشخصات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = [
        'name' => trim($_POST['name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
    ];
    $response = callAPI('PUT', '/auth/me', $payload);
    if (isset($response['success']) && $response['success'] === true) {
        $_SESSION['user_name'] = $payload['name'];
        $alert_message = 'مشخصات با موفقیت ویرایش شد.';
        $alert_type = 'success';
        $user_name = $payload['name'];
        $user_phone = $payload['phone'] !== '' ? $payload['phone'] : '';
    } else {
        $alert_message = $response['message'] ?? 'خطا در ذخیره مشخصات. لطفاً دوباره تلاش کنید.';
    }
}

$page_title = 'ویرایش مشخصات';
$header_sub = 'حساب کاربری';
$back_url = 'profile.php';
$nav_active = 'profile';
require_once 'includes/header.php';
?>

        <!-- کارت ویرایش مشخصات -->
        <section class="building-hero-card" style="margin-top: 8px;">
            <div class="building-details-wrapper" style="align-items: center; text-align: center;">
                <div class="building-header-row" style="justify-content: center;">
                    <div class="w-20 h-20 rounded-full bg-white p-1" style="background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.15);">
                        <div class="w-full h-full rounded-full" style="background: linear-gradient(135deg, var(--gold-primary), var(--gold-secondary)); display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 900; color: #0a1931;">
                            <?= htmlspecialchars(mb_substr($user_name ?: 'کاربر', 0, 1, 'UTF-8')) ?>
                        </div>
                    </div>
                </div>
                <div style="margin-top: 10px;">
                    <h2 style="font-size: 17px; font-weight: 800; color: var(--text-white);"><?= htmlspecialchars($user_name ?: 'کاربر') ?></h2>
                    <p style="font-size: 11px; color: var(--text-muted-white); margin-top: 3px;" dir="ltr"><?= htmlspecialchars($user_email) ?></p>
                </div>
            </div>
        </section>

        <?php if (!empty($alert_message)): ?>
            <div class="app-alert app-alert-<?= $alert_type === 'success' ? 'success' : 'error' ?>"<?= $alert_type === 'success' ? ' data-auto-hide' : '' ?>>
                <span><?= htmlspecialchars($alert_message) ?></span>
            </div>
        <?php endif; ?>

        <!-- فرم ویرایش مشخصات -->
        <section class="card p-5" style="margin-top: 20px;">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                ویرایش مشخصات
            </h3>
            <form method="POST" action="" class="space-y-4">
                <div>
                    <label for="name" class="form-label">نام و نام خانوادگی *</label>
                    <input type="text" id="name" name="name" required class="form-input" placeholder="مثال: علی رضایی"
                           value="<?= htmlspecialchars($user_name) ?>">
                </div>
                <div>
                    <label for="phone" class="form-label">شماره موبایل (نام کاربری ورود) *</label>
                    <input type="tel" id="phone" name="phone" dir="ltr" required inputmode="numeric" class="form-input text-left" placeholder="09123456789"
                           value="<?= htmlspecialchars($user_phone) ?>">
                    <p class="text-[11px] text-gray-400 mt-1">با همین شماره وارد سیستم می‌شوید؛ باید یکتا و معتبر باشد.</p>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        ذخیره تغییرات
                    </button>
                    <a href="profile.php" class="flex-1 text-center text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-3 px-4 rounded-xl transition-colors">
                        انصراف
                    </a>
                </div>
            </form>
        </section>

        <a href="profile.php" class="block text-center text-xs text-gray-400 mt-4">بازگشت به پروفایل</a>

<?php require_once 'includes/footer.php'; ?>
