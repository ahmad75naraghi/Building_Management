<?php
/**
 * صفحه ورود و ثبت‌نام یکپارچه شد.
 * این فایل فقط برای سازگاری با لینک‌ها و بوکمارک‌های قدیمی نگه داشته شده
 * و کاربر را به auth.php هدایت می‌کند.
 */
$redirect = trim((string) ($_GET['redirect'] ?? ''));
$target = 'auth.php';
if ($redirect !== ''
    && !preg_match('#^(https?:)?//#i', $redirect)
    && strpos($redirect, '..') === false
    && !preg_match('#[\r\n]#', $redirect)) {
    $target .= '?redirect=' . urlencode($redirect);
}
header('Location: ' . $target, true, 301);
exit;
