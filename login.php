<?php
require_once 'includes/api_helper.php';

if (isset($_SESSION['token']) && !empty($_SESSION['token'])) {
    header("Location: index.php");
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = normalize_phone($_POST['phone'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($phone) || empty($password)) {
        $error_message = 'لطفاً شماره موبایل و رمز عبور را وارد کنید.';
    } elseif (!is_valid_phone($phone)) {
        $error_message = 'شماره موبایل معتبر نیست. مثال: 09123456789';
    } else {
        $loginData = [
            'phone' => $phone,
            'password' => $password
        ];

        $response = callAPI('POST', '/auth/login', $loginData);

        if (isset($response['success']) && $response['success'] === true) {
            $token = $response['token'] ?? ($response['data']['token'] ?? null);
            if ($token) {
                $_SESSION['token'] = $token;
                $user = $response['data']['user'] ?? [];
                if (!empty($user['name'])) {
                    $_SESSION['user_name'] = $user['name'];
                }
                // بازگشت به صفحه مقصد (مثلاً پذیرش دعوتنامه) اگر امن و محلی باشد
                $redirect = trim((string) ($_GET['redirect'] ?? ''));
                if ($redirect !== ''
                    && !preg_match('#^(https?:)?//#i', $redirect)
                    && strpos($redirect, '..') === false
                    && !preg_match('#[\r\n]#', $redirect)) {
                    header("Location: " . $redirect);
                } else {
                    header("Location: index.php");
                }
                exit;
            }
        } else {
            // هندل کردن ارورهای دیتابیس یا شبکه
            if (isset($response['errors']) && is_array($response['errors'])) {
                $error_message = implode('<br>', array_map(function($e) { return implode(', ', (array) $e); }, $response['errors']));
            } elseif (isset($response['raw_error'])) {
                $error_message = "<strong>خطای فایروال:</strong><br>کد: " . $response['http_code'] . "<br>متن: " . $response['raw_error'];
            } else {
                $error_message = $response['message'] ?? 'شماره موبایل یا رمز عبور اشتباه است.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <!-- تنظیمات دقیق برای نسخه موبایل (جلوگیری از زوم ناخواسته) -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ورود | مدیریت ساختمان</title>

    <!-- لود فونت زیبای وزیرمتن -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.0.0/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- استفاده از Tailwind CSS برای طراحی حرفه‌ای و سریع -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: #f3f4f6; /* رنگ پس‌زمینه ملایم */
            -webkit-tap-highlight-color: transparent; /* حذف چشمک زدن دکمه‌ها در موبایل */
        }
        /* کادر ورود مخصوص موبایل */
        .mobile-container {
            max-width: 480px;
            margin: 0 auto;
            min-height: 100vh;
            background: #ffffff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        /* لودینگ روی دکمه */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
            margin-right: 8px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="antialiased text-gray-800">

    <div class="mobile-container relative overflow-hidden">

        <!-- افکت گرافیکی پس‌زمینه (دلخواه برای زیبایی) -->
        <div class="absolute top-0 right-0 w-64 h-64 bg-blue-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 -mr-20 -mt-20"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-indigo-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 -ml-20 -mb-20"></div>

        <div class="px-8 py-10 z-10 relative">
            <!-- لوگو و عنوان -->
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-blue-600 text-white shadow-lg mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900">مدیریت ساختمان پرو</h1>
                <p class="text-sm text-gray-500 mt-2">با شماره موبایل خود وارد شوید</p>
            </div>

            <!-- نمایش خطا -->
            <?php if (!empty($error_message)): ?>
            <div class="bg-red-50 text-red-600 border border-red-200 rounded-xl p-4 text-sm mb-6 flex items-center shadow-sm transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span><?= htmlspecialchars($error_message) ?></span>
            </div>
            <?php endif; ?>

            <!-- فرم ورود -->
            <form id="loginForm" method="POST" action="" class="space-y-5">
                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">شماره موبایل (نام کاربری)</label>
                    <div class="relative">
                        <input type="tel" id="phone" name="phone" dir="ltr" required inputmode="numeric" pattern="09[0-9]{9}"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-left bg-gray-50 focus:bg-white"
                            placeholder="09123456789" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">رمز عبور</label>
                    <div class="relative">
                        <input type="password" id="password" name="password" dir="ltr" required
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-shadow text-left bg-gray-50 focus:bg-white"
                            placeholder="••••••••">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between mt-2">
                    <div class="flex items-center">
                        <input id="remember" name="remember" type="checkbox" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="remember" class="mr-2 block text-sm text-gray-700">مرا به خاطر بسپار</label>
                    </div>
                    <div class="text-sm">
                        <span class="text-gray-400">فراموشی رمز؟ با مدیر ساختمان تماس بگیرید</span>
                    </div>
                </div>

                <button type="submit" id="submitBtn"
                    class="w-full flex justify-center items-center bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-4 rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 mt-6">
                    <span id="btnText">ورود به سیستم</span>
                    <div class="spinner" id="spinner"></div>
                </button>
            </form>

            <p class="text-center text-sm text-gray-500 mt-6">
                حساب کاربری ندارید؟
                <a href="register.php" class="font-bold text-blue-600 hover:text-blue-700 transition">ثبت‌نام کنید</a>
            </p>
            <p class="text-center text-sm text-gray-500 mt-4">
                نسخه 1.0.0 &copy; ۱۴۰۵
            </p>
        </div>
    </div>

    <script>
        // اضافه کردن افکت لودینگ موقع زدن دکمه ورود
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            var phone = document.getElementById('phone').value.replace(/[۰-۹]/g, function(d) {
                return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);
            }).replace(/[^0-9]/g, '');
            if (phone.length === 10 && phone.charAt(0) === '9') phone = '0' + phone;
            document.getElementById('phone').value = phone;
            if (!/^09[0-9]{9}$/.test(phone)) {
                e.preventDefault();
                alert('شماره موبایل معتبر نیست. مثال: 09123456789');
                return;
            }
            var btn = document.getElementById('submitBtn');
            var text = document.getElementById('btnText');
            var spinner = document.getElementById('spinner');

            btn.classList.add('opacity-90', 'cursor-not-allowed');
            text.innerText = 'در حال ارتباط...';
            spinner.style.display = 'block';
        });
    </script>
    <script src="assets/js/main.js"></script>
</body>
</html>
