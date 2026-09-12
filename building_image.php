<?php
/**
 * ==================== ارائهٔ تصویر سفارشی ساختمان ====================
 * تصویر کاور آپلودشده خارج از دسترس مستقیم وب (پوشهٔ ذخیره‌سازی) نگهداری
 * می‌شود؛ این اسکریپت تنها دروازهٔ نمایش آن است:
 *   ۱. نشست کاربر بررسی می‌شود،
 *   ۲. عضویت کاربر در ساختمان از API پرسیده می‌شود،
 *   ۳. فقط در صورت مجازبودن، تصویر با هدرهای امنیتی جریان می‌یابد.
 *
 * پارامتر:  id  شناسهٔ ساختمان (الزامی)
 */
require_once 'includes/api_helper.php';

/** ختم با خطای سادهٔ ۴۰۴ */
function building_image_fail(): never
{
    http_response_code(404);
    header('Content-Type: image/svg+xml; charset=UTF-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>';
    exit;
}

if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    building_image_fail();
}

$building_id = (int) ($_GET['id'] ?? 0);
if ($building_id <= 0) {
    building_image_fail();
}

// عضویت کاربر در ساختمان (پاسخ ناموفق = بدون دسترسی)
$response = callAPI('GET', '/buildings/' . $building_id);
if (empty($response['success']) || empty($response['data'])) {
    building_image_fail();
}
$building = $response['data'];

$path = (string) ($building['custom_logo_path'] ?? '');
if ($path === '' || !is_file($path)) {
    building_image_fail();
}

// جلوگیری از مسیردهی به بیرون ریشهٔ ذخیره‌سازی
$real = realpath($path);
$storageRoot = realpath(\App\Config\AppConfig::STORAGE_PATH);
if ($real === false || $storageRoot === false || !str_starts_with($real, $storageRoot . DIRECTORY_SEPARATOR)) {
    building_image_fail();
}

$mime = mime_content_type($real) ?: 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    building_image_fail();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($real));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($real);
exit;
