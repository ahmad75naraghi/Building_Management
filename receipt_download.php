<?php
/**
 * ==================== نمایش/دانلود امن فیش واریزی ====================
 * صفحهٔ مستقل (standalone) — بدون هدر/فوتر مشترک.
 * تنها دروازهٔ دیدن رسید پرداخت‌ها. فایل‌ها با نام تصادفی و خارج از
 * دسترس مستقیم وب در پوشهٔ «ساختمان/واحد» ذخیره می‌شوند؛ این اسکریپت:
 *   ۱. نشست کاربر را بررسی می‌کند،
 *   ۲. دسترسی (پرداخت‌کننده یا مدیر ساختمان) را از API می‌پرسد،
 *   ۳. فقط در صورت مجازبودن، فایل را با هدرهای امنیتی جریان می‌دهد.
 *
 * پارامترها:
 *   payment_id  شناسه ردیف پرداخت (الزامی)
 *   dl          اگر 1 باشد فایل به‌جای نمایش، دانلود می‌شود
 */
require_once 'includes/api_helper.php';

/** ختم با پیام خطای ساده (بدون افشای جزئیات) */
function receipt_download_fail(string $message): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
        . '<title>فیش در دسترس نیست</title>'
        . '<style>body{font-family:Tahoma,Vazirmatn,sans-serif;background:#f3f4f6;display:flex;'
        . 'align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.box{background:#fff;border-radius:16px;padding:32px 40px;text-align:center;'
        . 'box-shadow:0 10px 30px rgba(0,0,0,.08)}'
        . 'a{color:#2563eb;text-decoration:none;font-weight:700}</style></head><body>'
        . '<div class="box"><div style="font-size:40px;margin-bottom:10px;">🔒</div>'
        . '<p style="font-weight:700;margin:0 0 12px;">' . htmlspecialchars($message) . '</p>'
        . '<a href="costs.php">بازگشت به بخش مالی</a></div></body></html>';
    exit;
}

// ۱) ورود کاربر
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header('Location: auth.php');
    exit;
}

$payment_id = (int) ($_GET['payment_id'] ?? 0);
if ($payment_id <= 0) {
    receipt_download_fail('درخواست معتبر نیست.');
}

// ۲) پرسش از API — دسترسی (پرداخت‌کننده یا مدیر) در این مرحله اعمال می‌شود
$response = callAPI('GET', '/payments/' . $payment_id);
if (empty($response['success']) || empty($response['data'])) {
    receipt_download_fail($response['message'] ?? 'فیش پیدا نشد یا شما به آن دسترسی ندارید.');
}
$payment = $response['data'];

$receipt_path = (string) ($payment['receipt_path'] ?? '');
if ($receipt_path === '' || !is_file($receipt_path)) {
    receipt_download_fail('برای این پرداخت فیش واریزی ثبت نشده است.');
}

// ۳) جلوگیری از مسیردهی به بیرون ریشهٔ ذخیره‌سازی
$real = realpath($receipt_path);
$storageRoot = realpath(\App\Config\AppConfig::getStoragePath('buildings', (int) ($payment['building_id'] ?? 0)));
if ($real === false || $storageRoot === false || strncmp($real, $storageRoot, strlen($storageRoot)) !== 0) {
    receipt_download_fail('فایل فیش معتبر نیست.');
}

// ۴) جریان‌دهی فایل با هدرهای امنیتی
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $real);
        finfo_close($finfo);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }
}

$is_image = strncmp($mime, 'image/', 6) === 0;
$as_download = !empty($_GET['dl']) || !$is_image;
$filename = basename($real);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: ' . ($as_download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($real);
exit;
