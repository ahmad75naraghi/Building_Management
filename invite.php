<?php
require_once 'includes/api_helper.php';

$token = trim($_GET['token'] ?? '');
$invitation = null;
$alert_message = '';
$alert_type = 'error';

// اگر لاگین نیست، اول به ورود برود و برگردد
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php?redirect=" . urlencode('invite.php?token=' . urlencode($token)));
    exit;
}

// جزئیات دعوت (در صورت وجود توکن)
if ($token !== '') {
    $inv_response = callAPI('GET', '/invitations/info?token=' . urlencode($token));
    if (isset($inv_response['success']) && $inv_response['success'] === true) {
        $invitation = $inv_response['data'] ?? null;
    }
}

// پذیرش دعوت
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
    $response = callAPI('POST', '/invitations/accept', ['token' => $token]);
    if (isset($response['success']) && $response['success'] === true) {
        $alert_message = 'دعوتنامه با موفقیت پذیرفته شد. شما عضو ساختمان شدید.';
        $alert_type = 'success';
        $invitation = null; // دیگر دعوت معتبر نیست
    } else {
        $alert_message = $response['message'] ?? 'خطا در پذیرش دعوتنامه.';
    }
}

$page_title = 'پذیرش دعوتنامه';
$header_sub = 'عضویت در ساختمان';
$back_url = 'index.php';
$active_nav = 'none';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <?php if ($token === ''): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">✉️</div>
            لینک دعوت نامعتبر است. لطفاً لینک دعوت را از طریق پیامک یا ایمیل باز کنید.
        </div>
    <?php elseif ($alert_type === 'success'): ?>
        <div class="card p-6 text-center">
            <div class="text-5xl mb-3">✅</div>
            <h3 class="font-bold text-gray-800 text-lg">درخواست شما ثبت شد</h3>
            <p class="text-sm text-gray-500 mt-2"><?= htmlspecialchars($alert_message) ?></p>
            <a href="index.php" class="btn-primary mt-5 inline-block">رفتن به داشبورد</a>
        </div>
    <?php else: ?>
        <div class="card p-6">
            <div class="text-center mb-5">
                <div class="text-5xl mb-3">🏢</div>
                <h3 class="font-bold text-gray-800 text-lg">دعوت به ساختمان</h3>
                <?php if ($invitation): ?>
                    <?php if (!empty($invitation['invited_name'])): ?>
                        <p class="text-sm text-gray-500 mt-1">
                            <span class="font-bold text-gray-800"><?= htmlspecialchars($invitation['invited_name']) ?></span>
                            عزیز،
                        </p>
                    <?php endif; ?>
                    <p class="text-sm text-gray-500 mt-2">
                        شما به ساختمان
                        <span class="font-bold text-gray-800"><?= htmlspecialchars($invitation['building_name'] ?? '') ?></span>
                        دعوت شده‌اید.
                    </p>
                    <?php if (!empty($invitation['role'])): ?>
                        <span class="inline-block mt-2 text-[11px] px-2.5 py-1 rounded-full bg-blue-50 text-blue-700">
                            نقش: <?= htmlspecialchars(($invitation['role'] === 'manager') ? 'مدیر' : (($invitation['role'] === 'owner') ? 'مالک' : (($invitation['role'] === 'tenant') ? 'مستأجر' : (($invitation['role'] === 'accountant') ? 'حسابدار' : 'ساکن')))) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($invitation['unit'])): ?>
                        <span class="inline-block mt-2 mr-1 text-[11px] px-2.5 py-1 rounded-full bg-amber-50 text-amber-700">
                            واحد <?= htmlspecialchars($invitation['unit']['unit_number'] ?? '') ?>
                            (<?= htmlspecialchars(($invitation['role'] === 'owner') ? 'مالک' : 'مستأجر') ?> این واحد)
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-sm text-gray-500 mt-2">با پذیرش این دعوت‌نامه، عضو ساختمان می‌شوید.</p>
                <?php endif; ?>
            </div>

            <?php if (!empty($alert_message)): ?>
                <div class="app-alert app-alert-<?= $alert_type === 'success' ? 'success' : 'error' ?> mb-4">
                    <?= htmlspecialchars($alert_message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" data-confirm="آیا این دعوت‌نامه را می‌پذیرید؟">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <button type="submit" class="btn-primary w-full">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    پذیرش دعوت‌نامه
                </button>
            </form>
            <a href="index.php" class="block text-center text-xs text-gray-400 mt-4">بازگشت به داشبورد</a>
        </div>
    <?php endif; ?>

</main>

<?php require_once 'includes/page_tail.php'; ?>
