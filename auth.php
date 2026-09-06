<?php
/* ============================================================
 * ورود و ثبت‌نام یکپارچه — یک صفحه، چند گام
 *
 * گام‌ها ($step):
 *   phone    → فقط شماره موبایل گرفته می‌شود
 *   password → کاربر ثبت‌نام‌شده با رمز، رمزش را وارد می‌کند
 *   otp      → کد یک‌بارمصرف پیامک‌شده وارد می‌شود
 *   name     → کاربر جدید نام و نام خانوادگی را وارد می‌کند
 *   setpass  → کاربر رمز عبور خود را تعیین می‌کند
 * ============================================================ */
require_once 'includes/api_helper.php';

// کاربر کاملاً لاگین‌شده (نام و رمز دارد) به صفحه اصلی می‌رود
if (!empty($_SESSION['token']) && empty($_SESSION['auth_pending'])) {
    header('Location: index.php');
    exit;
}

$error_message = '';
$info_message = '';
$debug_code = null;

// مقصد پس از ورود موفق (مثلاً پذیرش دعوت‌نامه)
$redirect = trim((string) ($_GET['redirect'] ?? $_POST['redirect'] ?? ''));
$redirect_is_safe = $redirect !== ''
    && !preg_match('#^(https?:)?//#i', $redirect)
    && strpos($redirect, '..') === false
    && !preg_match('#[\r\n]#', $redirect);
$redirect_target = $redirect_is_safe ? $redirect : 'index.php';

// گام جاری و شماره در حال پردازش
$step = $_SESSION['auth_step'] ?? 'phone';
$phone = $_SESSION['auth_phone'] ?? '';

/**
 * پایان موفق جریان: پاک‌کردن وضعیت موقت و انتقال به مقصد.
 */
function auth_finish(string $target): void
{
    unset($_SESSION['auth_step'], $_SESSION['auth_phone'], $_SESSION['auth_pending'], $_SESSION['auth_retry_after']);
    header('Location: ' . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? '';

    // ---------- بازگشت به گام شماره موبایل ----------
    if ($action === 'restart') {
        unset($_SESSION['auth_step'], $_SESSION['auth_phone'], $_SESSION['auth_pending'], $_SESSION['auth_retry_after']);
        header('Location: auth.php' . ($redirect_is_safe ? '?redirect=' . urlencode($redirect) : ''));
        exit;
    }

    // ---------- گام ۱: بررسی شماره موبایل ----------
    if ($action === 'check_phone') {
        $phone = normalize_phone($_POST['phone'] ?? '');
        if (!is_valid_phone($phone)) {
            $error_message = 'شماره موبایل معتبر نیست. مثال: 09123456789';
            $step = 'phone';
        } else {
            $response = callAPI('POST', '/auth/check-phone', ['phone' => $phone]);
            if (!empty($response['success'])) {
                $_SESSION['auth_phone'] = $phone;
                $next = $response['data']['next'] ?? 'otp';

                if ($next === 'password') {
                    // کاربر شناخته‌شده با رمز → درخواست رمز
                    $step = $_SESSION['auth_step'] = 'password';
                } else {
                    // کاربر جدید یا بدون رمز → ارسال کد یک‌بارمصرف
                    $otp = callAPI('POST', '/auth/send-otp', ['phone' => $phone]);
                    if (!empty($otp['success'])) {
                        $step = $_SESSION['auth_step'] = 'otp';
                        $info_message = 'کد تأیید به شماره ' . fa_digits($phone) . ' پیامک شد.';
                        $debug_code = $otp['data']['debug_code'] ?? null;
                        $_SESSION['auth_retry_after'] = time() + (int) ($otp['data']['retry_after'] ?? 60);
                    } elseif (($otp['http_code'] ?? 0) === 429) {
                        // کد قبلی هنوز معتبر است
                        $step = $_SESSION['auth_step'] = 'otp';
                        $info_message = 'کد قبلی هنوز معتبر است. همان را وارد کنید.';
                        $_SESSION['auth_retry_after'] = time() + (int) ($otp['data']['retry_after'] ?? 60);
                    } else {
                        $error_message = $otp['message'] ?? 'ارسال کد تأیید ناموفق بود.';
                        $step = 'phone';
                    }
                }
            } else {
                $error_message = $response['message'] ?? 'بررسی شماره موبایل ناموفق بود.';
                $step = 'phone';
            }
        }
    }

    // ---------- گام ۲الف: ورود با رمز عبور ----------
    elseif ($action === 'login_password') {
        $password = (string) ($_POST['password'] ?? '');
        if ($phone === '') {
            $step = 'phone';
            $error_message = 'ابتدا شماره موبایل خود را وارد کنید.';
        } elseif ($password === '') {
            $step = 'password';
            $error_message = 'رمز عبور را وارد کنید.';
        } else {
            $response = callAPI('POST', '/auth/login', ['phone' => $phone, 'password' => $password]);
            $token = $response['data']['token'] ?? ($response['token'] ?? null);
            if (!empty($response['success']) && $token) {
                $_SESSION['token'] = $token;
                $user = $response['data']['user'] ?? [];
                if (!empty($user['name'])) {
                    $_SESSION['user_name'] = $user['name'];
                }
                auth_finish($redirect_target);
            } else {
                $step = 'password';
                $error_message = $response['message'] ?? 'رمز عبور اشتباه است.';
            }
        }
    }

    // ---------- ارسال مجدد کد ----------
    elseif ($action === 'resend_otp') {
        if ($phone === '') {
            $step = 'phone';
        } else {
            $otp = callAPI('POST', '/auth/send-otp', ['phone' => $phone]);
            $step = $_SESSION['auth_step'] = 'otp';
            if (!empty($otp['success'])) {
                $info_message = 'کد تأیید مجدداً ارسال شد.';
                $debug_code = $otp['data']['debug_code'] ?? null;
                $_SESSION['auth_retry_after'] = time() + (int) ($otp['data']['retry_after'] ?? 60);
            } else {
                $error_message = $otp['message'] ?? 'ارسال مجدد ناموفق بود.';
                $_SESSION['auth_retry_after'] = time() + (int) ($otp['data']['retry_after'] ?? 60);
            }
        }
    }

    // ---------- گام ۲ب: تأیید کد یک‌بارمصرف ----------
    elseif ($action === 'verify_otp') {
        $code = preg_replace('/\D/', '', en_digits($_POST['code'] ?? '')) ?? '';
        if ($phone === '') {
            $step = 'phone';
            $error_message = 'نشست شما منقضی شده است. دوباره شماره را وارد کنید.';
        } elseif ($code === '') {
            $step = 'otp';
            $error_message = 'کد تأیید را وارد کنید.';
        } else {
            $response = callAPI('POST', '/auth/verify-otp', ['phone' => $phone, 'code' => $code]);
            $token = $response['data']['token'] ?? null;
            if (!empty($response['success']) && $token) {
                $_SESSION['token'] = $token;
                $user = $response['data']['user'] ?? [];
                if (!empty($user['name'])) {
                    $_SESSION['user_name'] = $user['name'];
                }

                $next = $response['data']['next'] ?? 'done';
                if ($next === 'name') {
                    // کاربر جدید: ابتدا نام، سپس رمز
                    $_SESSION['auth_pending'] = 1;
                    $step = $_SESSION['auth_step'] = 'name';
                } elseif ($next === 'password') {
                    // کاربر قدیمی بدون رمز: مستقیم به ست‌کردن رمز
                    $_SESSION['auth_pending'] = 1;
                    $step = $_SESSION['auth_step'] = 'setpass';
                } else {
                    auth_finish($redirect_target);
                }
            } else {
                $step = 'otp';
                $error_message = $response['message'] ?? 'کد تأیید نادرست است.';
            }
        }
    }

    // ---------- گام ۳: ثبت نام و نام خانوادگی ----------
    elseif ($action === 'complete_name') {
        $name = trim($_POST['name'] ?? '');
        if (mb_strlen($name) < 3) {
            $step = 'name';
            $error_message = 'نام و نام خانوادگی را کامل وارد کنید.';
        } else {
            $response = callAPI('POST', '/auth/complete-name', ['name' => $name]);
            if (!empty($response['success'])) {
                // توکن تازه شامل نام به‌روزشده است
                if (!empty($response['data']['token'])) {
                    $_SESSION['token'] = $response['data']['token'];
                }
                $_SESSION['user_name'] = $name;
                $step = $_SESSION['auth_step'] = 'setpass';
                $info_message = 'خوش آمدید ' . $name . '! برای تکمیل ثبت‌نام یک رمز عبور تعیین کنید.';
            } else {
                $step = 'name';
                $error_message = $response['message'] ?? 'ثبت نام ناموفق بود.';
            }
        }
    }

    // ---------- گام ۴: تعیین رمز عبور ----------
    elseif ($action === 'set_password') {
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        if (mb_strlen($password) < 6) {
            $step = 'setpass';
            $error_message = 'رمز عبور باید حداقل ۶ کاراکتر باشد.';
        } elseif ($password !== $confirmation) {
            $step = 'setpass';
            $error_message = 'تکرار رمز عبور مطابقت ندارد.';
        } else {
            $response = callAPI('POST', '/auth/set-password', [
                'password' => $password,
                'password_confirmation' => $confirmation,
            ]);
            if (!empty($response['success'])) {
                auth_finish($redirect_target);
            } else {
                $step = 'setpass';
                $error_message = $response['message'] ?? 'تعیین رمز عبور ناموفق بود.';
            }
        }
    }
}

// اگر گام نیاز به شماره دارد ولی شماره‌ای در نشست نیست، به گام اول برگرد
if (in_array($step, ['password', 'otp'], true) && $phone === '') {
    $step = 'phone';
}
// گام‌های نیازمند توکن
if (in_array($step, ['name', 'setpass'], true) && empty($_SESSION['token'])) {
    $step = 'phone';
}

$resend_in = max(0, (int) (($_SESSION['auth_retry_after'] ?? 0) - time()));
$masked_phone = $phone !== '' ? fa_digits(substr($phone, 0, 4) . '***' . substr($phone, -4)) : '';

$step_titles = [
    'phone' => ['ورود یا ثبت‌نام', 'برای ورود، شماره موبایل خود را وارد کنید'],
    'password' => ['خوش آمدید', 'رمز عبور حساب خود را وارد کنید'],
    'otp' => ['تأیید شماره موبایل', 'کد ۶ رقمی ارسال‌شده را وارد کنید'],
    'name' => ['تکمیل ثبت‌نام', 'نام و نام خانوادگی خود را وارد کنید'],
    'setpass' => ['تعیین رمز عبور', 'برای ورودهای بعدی یک رمز عبور انتخاب کنید'],
];
[$title, $subtitle] = $step_titles[$step] ?? $step_titles['phone'];

// شماره گام برای نوار پیشرفت (۱ تا ۳)
$step_index = ['phone' => 1, 'password' => 2, 'otp' => 2, 'name' => 3, 'setpass' => 3][$step] ?? 1;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($title) ?> | مدیریت ساختمان</title>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.0.0/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">

<div class="auth-shell">
    <div class="auth-glow auth-glow-a"></div>
    <div class="auth-glow auth-glow-b"></div>

    <div class="auth-card">

        <div class="auth-brand">
            <div class="auth-logo">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
            </div>
            <h1 class="auth-title"><?= htmlspecialchars($title) ?></h1>
            <p class="auth-subtitle"><?= htmlspecialchars($subtitle) ?></p>
        </div>

        <!-- نوار پیشرفت -->
        <div class="auth-steps" aria-hidden="true">
            <?php for ($i = 1; $i <= 3; $i++): ?>
                <span class="auth-step-dot<?= $i <= $step_index ? ' is-active' : '' ?>"></span>
            <?php endfor; ?>
        </div>

        <?php if ($error_message !== ''): ?>
            <div class="auth-alert auth-alert-error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        <?php if ($info_message !== ''): ?>
            <div class="auth-alert auth-alert-info"><?= htmlspecialchars($info_message) ?></div>
        <?php endif; ?>
        <?php if ($debug_code !== null): ?>
            <div class="auth-alert auth-alert-debug">
                حالت توسعه — کد تأیید: <strong dir="ltr"><?= htmlspecialchars($debug_code) ?></strong>
            </div>
        <?php endif; ?>

        <?php /* ---------------- گام ۱: شماره موبایل ---------------- */ ?>
        <?php if ($step === 'phone'): ?>
            <form method="POST" action="" class="auth-form" id="authForm">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="check_phone">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <div>
                    <label for="phone" class="auth-label">شماره موبایل</label>
                    <input type="tel" id="phone" name="phone" dir="ltr" required inputmode="numeric" autocomplete="tel"
                           class="auth-input" placeholder="09123456789"
                           value="<?= htmlspecialchars($phone) ?>" autofocus>
                    <p class="auth-hint">اگر حساب نداشته باشید، به‌صورت خودکار ثبت‌نام می‌شوید.</p>
                </div>
                <button type="submit" class="auth-btn">ادامه</button>
            </form>

        <?php /* ---------------- گام ۲الف: رمز عبور ---------------- */ ?>
        <?php elseif ($step === 'password'): ?>
            <div class="auth-phone-badge">
                <span dir="ltr"><?= fa_digits($phone) ?></span>
                <form method="POST" action="" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="restart">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <button type="submit" class="auth-link-btn">تغییر</button>
                </form>
            </div>

            <form method="POST" action="" class="auth-form" id="authForm">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="login_password">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <div>
                    <label for="password" class="auth-label">رمز عبور</label>
                    <input type="password" id="password" name="password" dir="ltr" required autocomplete="current-password"
                           class="auth-input" placeholder="••••••••" autofocus>
                </div>
                <button type="submit" class="auth-btn">ورود</button>
            </form>

            <form method="POST" action="" class="auth-secondary">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="resend_otp">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <button type="submit" class="auth-link-btn">رمز عبور را فراموش کرده‌ام — ورود با کد پیامکی</button>
            </form>

        <?php /* ---------------- گام ۲ب: کد یک‌بارمصرف ---------------- */ ?>
        <?php elseif ($step === 'otp'): ?>
            <div class="auth-phone-badge">
                <span dir="ltr"><?= $masked_phone ?></span>
                <form method="POST" action="" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="restart">
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                    <button type="submit" class="auth-link-btn">تغییر شماره</button>
                </form>
            </div>

            <form method="POST" action="" class="auth-form" id="authForm">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="verify_otp">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <div>
                    <label for="code" class="auth-label">کد تأیید</label>
                    <input type="text" id="code" name="code" dir="ltr" required inputmode="numeric"
                           maxlength="6" autocomplete="one-time-code"
                           class="auth-input auth-input-code" placeholder="––––––" autofocus>
                </div>
                <button type="submit" class="auth-btn">تأیید و ورود</button>
            </form>

            <form method="POST" action="" class="auth-secondary">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="resend_otp">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <button type="submit" class="auth-link-btn" id="resendBtn" <?= $resend_in > 0 ? 'disabled' : '' ?>
                        data-resend-in="<?= $resend_in ?>">
                    <?= $resend_in > 0 ? 'ارسال مجدد کد تا ' . fa_digits($resend_in) . ' ثانیه دیگر' : 'ارسال مجدد کد' ?>
                </button>
            </form>

        <?php /* ---------------- گام ۳: نام و نام خانوادگی ---------------- */ ?>
        <?php elseif ($step === 'name'): ?>
            <form method="POST" action="" class="auth-form" id="authForm">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="complete_name">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <div>
                    <label for="name" class="auth-label">نام و نام خانوادگی</label>
                    <input type="text" id="name" name="name" required autocomplete="name"
                           class="auth-input" placeholder="مثال: رضا محمدی" autofocus>
                    <p class="auth-hint">این نام برای مدیر و سایر ساکنین ساختمان نمایش داده می‌شود.</p>
                </div>
                <button type="submit" class="auth-btn">ادامه</button>
            </form>

        <?php /* ---------------- گام ۴: تعیین رمز عبور ---------------- */ ?>
        <?php elseif ($step === 'setpass'): ?>
            <form method="POST" action="" class="auth-form" id="authForm">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="set_password">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <div>
                    <label for="new_password" class="auth-label">رمز عبور جدید</label>
                    <input type="password" id="new_password" name="password" dir="ltr" required minlength="6"
                           autocomplete="new-password" class="auth-input" placeholder="حداقل ۶ کاراکتر" autofocus>
                </div>
                <div>
                    <label for="password_confirmation" class="auth-label">تکرار رمز عبور</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" dir="ltr" required
                           minlength="6" autocomplete="new-password" class="auth-input" placeholder="••••••••">
                </div>
                <button type="submit" class="auth-btn">ثبت رمز و ورود</button>
            </form>
        <?php endif; ?>

        <p class="auth-footer">نسخه ۱.۰.۰ &copy; ۱۴۰۵</p>
    </div>
</div>

<script>
    (function () {
        /* یکدست‌سازی ارقام فارسی در ورودی‌های عددی */
        function toEnglishDigits(value) {
            return value
                .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
                .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
        }

        var phoneInput = document.getElementById('phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function () {
                var v = toEnglishDigits(phoneInput.value).replace(/[^0-9]/g, '');
                if (v.length === 10 && v.charAt(0) === '9') { v = '0' + v; }
                phoneInput.value = v.slice(0, 11);
            });
        }

        var codeInput = document.getElementById('code');
        if (codeInput) {
            codeInput.addEventListener('input', function () {
                codeInput.value = toEnglishDigits(codeInput.value).replace(/[^0-9]/g, '').slice(0, 6);
                /* ارسال خودکار وقتی هر ۶ رقم وارد شد */
                if (codeInput.value.length === 6) {
                    codeInput.form.requestSubmit
                        ? codeInput.form.requestSubmit()
                        : codeInput.form.submit();
                }
            });
        }

        /* شمارش معکوس دکمه ارسال مجدد */
        var resendBtn = document.getElementById('resendBtn');
        if (resendBtn) {
            var left = parseInt(resendBtn.getAttribute('data-resend-in') || '0', 10);
            if (left > 0) {
                var faNum = function (n) {
                    return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
                };
                var timer = setInterval(function () {
                    left -= 1;
                    if (left <= 0) {
                        clearInterval(timer);
                        resendBtn.disabled = false;
                        resendBtn.textContent = 'ارسال مجدد کد';
                    } else {
                        resendBtn.textContent = 'ارسال مجدد کد تا ' + faNum(left) + ' ثانیه دیگر';
                    }
                }, 1000);
            }
        }

        /* جلوگیری از ارسال دوباره و نمایش وضعیت بارگذاری */
        var form = document.getElementById('authForm');
        if (form) {
            form.addEventListener('submit', function () {
                var btn = form.querySelector('.auth-btn');
                if (btn && !btn.disabled) {
                    btn.disabled = true;
                    btn.classList.add('is-loading');
                    btn.textContent = 'لطفاً صبر کنید...';
                }
            });
        }
    })();
</script>

</body>
</html>
