<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: login.php");
    exit;
}

$alert_message = '';
$alert_type = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = (string) ($_POST['current_password'] ?? '');
    $new_password = (string) ($_POST['new_password'] ?? '');
    $new_password_confirmation = (string) ($_POST['new_password_confirmation'] ?? '');

    if ($current_password === '' || $new_password === '') {
        $alert_message = 'همه فیلدها را پر کنید.';
    } else {
        $response = callAPI('PUT', '/auth/password', [
            'current_password' => $current_password,
            'new_password' => $new_password,
            'password_confirmation' => $new_password_confirmation,
        ]);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'رمز عبور با موفقیت تغییر کرد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در تغییر رمز عبور.';
        }
    }
}

$page_title = 'تغییر رمز عبور';
$header_sub = 'امنیت حساب کاربری';
$back_url = 'profile.php';
$active_nav = 'profile';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <div class="card p-5">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            تغییر رمز عبور
        </h3>

        <?php if (!empty($alert_message)): ?>
            <div class="app-alert app-alert-<?= $alert_type === 'success' ? 'success' : 'error' ?> mb-4">
                <?= htmlspecialchars($alert_message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-4">
            <div>
                <label for="current_password" class="form-label">رمز عبور فعلی *</label>
                <input type="password" id="current_password" name="current_password" required class="form-input" placeholder="••••••••">
            </div>
            <div>
                <label for="new_password" class="form-label">رمز عبور جدید * (حداقل ۸ کاراکتر)</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" class="form-input" placeholder="••••••••">
            </div>
            <div>
                <label for="new_password_confirmation" class="form-label">تکرار رمز عبور جدید *</label>
                <input type="password" id="new_password_confirmation" name="new_password_confirmation" required minlength="8" class="form-input" placeholder="••••••••">
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">ذخیره رمز جدید</button>
                <a href="profile.php" class="btn-secondary flex-1 text-center">انصراف</a>
            </div>
        </form>
    </div>

</main>

<?php require_once 'includes/page_tail.php'; ?>
