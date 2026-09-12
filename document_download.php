<?php
/**
 * ==================== دانلود امن سند ساختمان ====================
 * صفحهٔ مستقل (standalone) — بدون هدر/فوتر مشترک.
 * تنها دروازهٔ دریافت فایل اسناد. فایل‌ها با نام تصادفی و خارج از
 * دسترس مستقیم وب ذخیره می‌شوند؛ این اسکریپت:
 *   ۱. نشست کاربر را بررسی می‌کند،
 *   ۲. عضویت در ساختمان و «قابل رویت بودن» سند را از API می‌پرسد،
 *   ۳. فقط در صورت مجازبودن، فایل را با هدرهای امنیتی جریان می‌دهد.
 *
 * پارامترها:
 *   id  شناسه سند (الزامی)
 *   dl  اگر 1 باشد فایل به‌جای نمایش، با دیالوگ دانلود ذخیره می‌شود
 */
require_once 'includes/api_helper.php';

use App\Utilities\FileStorage;

/** ختم با پیام خطای ساده (بدون افشای جزئیات) */
function document_download_fail(string $message): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
        . '<title>سند در دسترس نیست</title>'
        . '<style>body{font-family:Tahoma,Vazirmatn,sans-serif;background:#f3f4f6;display:flex;'
        . 'align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.box{background:#fff;border-radius:16px;padding:32px 40px;text-align:center;'
        . 'box-shadow:0 10px 30px rgba(0,0,0,.08)}'
        . 'a{color:#2563eb;text-decoration:none;font-weight:700}</style></head><body>'
        . '<div class="box"><div style="font-size:40px;margin-bottom:10px;">🔒</div>'
        . '<p style="font-weight:700;margin:0 0 12px;">' . htmlspecialchars($message) . '</p>'
        . '<a href="index.php">بازگشت به برنامه</a></div></body></html>';
    exit;
}

// ۱) ورود کاربر
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header('Location: auth.php');
    exit;
}

$document_id = (int) ($_GET['id'] ?? 0);
if ($document_id <= 0) {
    document_download_fail('سند درخواستی معتبر نیست.');
}

// ۲) پرسش از API — عضویت ساختمان و سطح رویت سند در این مرحله اعمال می‌شود
$response = callAPI('GET', '/documents/' . $document_id);
if (empty($response['success']) || empty($response['data'])) {
    document_download_fail($response['message'] ?? 'سند پیدا نشد یا شما به آن دسترسی ندارید.');
}
$document = $response['data'];

// ۳) اسناد لینکی مستقیم به مقصد هدایت می‌شوند
if (empty($document['is_uploaded'])) {
    $target = (string) ($document['file_path'] ?? '');
    if ($target === '' || !preg_match('#^https?://#i', $target)) {
        document_download_fail('این سند آدرس معتبری ندارد.');
    }
    header('Location: ' . $target);
    exit;
}

// ۴) جریان‌دهی فایل از فضای محافظت‌شده
$building_id = (int) ($document['building_id'] ?? 0);
$stored_name = (string) ($document['stored_name'] ?? '');
try {
    $path = FileStorage::documentPath($building_id, $stored_name);
} catch (Throwable $e) {
    document_download_fail('فایل سند در دسترس نیست.');
}
if (!is_file($path)) {
    document_download_fail('فایل سند در دسترس نیست.');
}

$mime = (string) ($document['mime_type'] ?? '');
if ($mime === '' || strpos($mime, '/') === false) {
    $mime = 'application/octet-stream';
}

$title = (string) ($document['title'] ?? ('سند-' . $document_id));
$extension = pathinfo($stored_name, PATHINFO_EXTENSION);
$display_name = $title . ($extension !== '' ? '.' . $extension : '');

$disposition = !empty($_GET['dl']) ? 'attachment' : 'inline';

header_remove('Set-Cookie');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . "; filename*=UTF-8''" . rawurlencode($display_name));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
