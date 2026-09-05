<?php
require_once 'includes/api_helper.php';

if (isset($_SESSION['token']) && !empty($_SESSION['token'])) {
    header("Location: index.php");
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $password_confirmation = trim($_POST['password_confirmation'] ?? '');

    if (empty($name) || empty($email) || empty($password)) {
        $error_message = 'لطفاً تمامی فیلدها را پر کنید.';
    } elseif ($password !== $password_confirmation) {
        $error_message = 'تکرار رمز عبور با رمز عبور مطابقت ندارد.';
    } else {
        $registerData = [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password_confirmation
        ];
        
        $response = callAPI('POST', '/auth/register', $registerData);

        if (isset($response['success']) && $response['success'] === true) {
            $token = $response['token'] ?? ($response['data']['token'] ?? null);
            if ($token) {
                $_SESSION['token'] = $token;
                header("Location: index.php");
                exit;
            }
        } else {
            // استخراج هوشمندانه ارورهای ولیدیشن دیتابیس
            if (isset($response['errors']) && is_array($response['errors'])) {
                $error_message = implode('<br>', array_map(function($e) { return implode(', ', $e); }, $response['errors']));
            } else {
                $error_message = $response['message'] ?? 'خطای نامشخص در ثبت‌نام.';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ثبت‌نام | مدیریت ساختمان</title>
    
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.0.0/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: #f3f4f6;
            -webkit-tap-highlight-color: transparent;
        }
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
        
        <!-- افکت گرافیکی پس‌زمینه -->
        <div class="absolute top-0 right-0 w-64 h-64 bg-green-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 -mr-20 -mt-20"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-blue-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 -ml-20 -mb-20"></div>

        <div class="px-8 py-8 z-10 relative">
            <!-- لوگو و عنوان -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gray-900 text-white shadow-lg mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900">ایجاد حساب کاربری</h1>
                <p class="text-sm text-gray-500 mt-2">برای مدیریت ساختمان خود ثبت‌نام کنید</p>
            </div>

            <!-- نمایش خطا -->
            <?php if (!empty($error_message)): ?>
            <div class="bg-red-50 text-red-600 border border-red-200 rounded-xl p-4 text-sm mb-6 flex items-center shadow-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span><?= htmlspecialchars($error_message) ?></span>
            </div>
            <?php endif; ?>

            <!-- فرم ثبت‌نام -->
            <form id="registerForm" method="POST" action="" class="space-y-4">
                
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">نام و نام خانوادگی</label>
                    <input type="text" id="name" name="name" required
                        class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition-shadow bg-gray-50 focus:bg-white" 
                        placeholder="مثال: علی احمدی" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">ایمیل</label>
                    <div class="relative">
                        <input type="email" id="email" name="email" dir="ltr" required
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition-shadow text-left bg-gray-50 focus:bg-white" 
                            placeholder="user@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">رمز عبور</label>
                    <input type="password" id="password" name="password" dir="ltr" required minlength="6"
                        class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition-shadow text-left bg-gray-50 focus:bg-white" 
                        placeholder="••••••••">
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">تکرار رمز عبور</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" dir="ltr" required minlength="6"
                        class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition-shadow text-left bg-gray-50 focus:bg-white" 
                        placeholder="••••••••">
                </div>

                <div class="pt-2">
                    <button type="submit" id="submitBtn"
                        class="w-full flex justify-center items-center bg-gray-900 hover:bg-gray-800 text-white font-bold py-3.5 px-4 rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-900">
                        <span id="btnText">ثبت‌نام در سیستم</span>
                        <div class="spinner" id="spinner"></div>
                    </button>
                </div>
            </form>
            
            <div class="mt-8 text-center text-sm text-gray-600">
                حساب کاربری دارید؟ 
                <a href="login.php" class="font-bold text-blue-600 hover:text-blue-700 transition">وارد شوید</a>
            </div>
            
        </div>
    </div>

    <script>
        // لودینگ روی دکمه برای جلوگیری از ارسال دوگانه
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            var pass = document.getElementById('password').value;
            var conf = document.getElementById('password_confirmation').value;
            
            if (pass !== conf) {
                e.preventDefault();
                alert('تکرار رمز عبور مطابقت ندارد.');
                return;
            }

            var btn = document.getElementById('submitBtn');
            var text = document.getElementById('btnText');
            var spinner = document.getElementById('spinner');
            
            btn.classList.add('opacity-90', 'cursor-not-allowed');
            text.innerText = 'در حال ثبت اطلاعات...';
            spinner.style.display = 'block';
        });
    </script>
    <script src="assets/js/main.js"></script>
</body>
</html> 