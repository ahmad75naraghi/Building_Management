<?php
/**
 * تغییرمسیر سازگاری — صفحهٔ «پروفایل ساختمان» با «داشبورد» یکپارچه شده است.
 * همهٔ بخش‌های ساختمان (ساختار مجتمع، نمای واحدها، ماژول‌ها و اطلاعات اجمالی)
 * اکنون در dashboard.php نمایش داده می‌شوند.
 */
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header('Location: auth.php');
    exit;
}

$building_id = (int) ($_GET['id'] ?? $_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

$target = 'dashboard.php';
if ($building_id > 0) {
    $target .= '?building_id=' . $building_id;
    // لینک‌های قدیمی «نمایش در نما» با واحد مشخص همچنان کار کنند
    if (isset($_GET['unit']) && (int) $_GET['unit'] > 0) {
        $target .= '&unit=' . (int) $_GET['unit'];
    }
}

header('Location: ' . $target);
exit;
