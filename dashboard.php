<?php
// داشبورد اصلی در index.php قرار دارد؛ این صفحه برای سازگاری به داشبورد هدایت می‌کند.
require_once 'includes/api_helper.php';

if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: login.php");
    exit;
}

header("Location: index.php");
exit;
