<?php
require_once 'includes/api_helper.php';

// ارسال درخواست به سرور جهت باطل کردن توکن در صورت نیاز
if (isset($_SESSION['token']) && !empty($_SESSION['token'])) {
    callAPI('POST', '/auth/logout');
}

// از بین بردن تمام متغیرهای سشن در فرانت‌اند
session_destroy();

// هدایت کاربر به صفحه لاگین
header("Location: auth.php");
exit;
?>