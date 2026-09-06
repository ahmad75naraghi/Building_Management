<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $response = callAPI('DELETE', '/buildings/' . $building_id);
    if (isset($response['success']) && $response['success'] === true) {
        // پاک کردن ساختمان فعال از سشن
        if ((int) ($_SESSION['active_building_id'] ?? 0) === $building_id) {
            unset($_SESSION['active_building_id']);
        }
        header("Location: index.php");
        exit;
    }
    $error = $response['message'] ?? 'خطا در حذف ساختمان.';
} else {
    $error = 'درخواست نامعتبر است.';
}

// در صورت خطا به صفحه ویرایش برگرد
header("Location: building_edit.php?id=" . $building_id . "&error=" . urlencode($error ?? ''));
exit;
