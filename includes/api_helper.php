<?php
session_start();

// آدرس دقیق API — در حالت عادی همان سرور اصلی است.
// برای اجرای محلی می‌توانید بدون دست‌زدن به این فایل، متغیر محیطی API_BASE_URL را تنظیم کنید:
//   API_BASE_URL=http://localhost:8000/b/api php -S localhost:8080
$apiBaseUrl = getenv('API_BASE_URL');
if (!is_string($apiBaseUrl) || $apiBaseUrl === '') {
    $apiBaseUrl = 'https://file.falnic.com/b/api';
}
define('API_BASE_URL', $apiBaseUrl);

function callAPI($method, $endpoint, $data = false) {
    $curl = curl_init();
    
    $endpoint = ltrim($endpoint, '/');
    $url = API_BASE_URL . '/' . $endpoint;

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    if (isset($_SESSION['token']) && !empty($_SESSION['token'])) {
        $headers[] = 'Authorization: Bearer ' . $_SESSION['token'];
    }

    $jsonData = $data ? json_encode($data) : '';

    switch (strtoupper($method)) {
        case "POST":
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
            if ($data) curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
            break;
        case "PUT":
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
            if ($data) curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
            break;
        case "DELETE":
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
        default: // GET
            if ($data) {
                $url .= '?' . http_build_query($data);
            }
    }

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    
    // غیرفعال کردن موقت سخت‌گیری SSL برای ارتباط درون‌سروری
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    
    $result = curl_exec($curl);
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    $result_string = is_string($result) ? $result : '';
    $response = json_decode($result_string, true);
    
    if (!is_array($response)) {
        return [
            'success' => false,
            'message' => 'ارتباط با API برقرار نشد.',
            'raw_error' => htmlspecialchars(substr($result_string, 0, 250)),
            'http_code' => $http_status
        ];
    }
    
    $response['http_code'] = $http_status;
    return $response;
}

/**
 * ارسال درخواست POST با فرم چندبخشی (آپلود فایل).
 *
 * @param string $endpoint
 * @param array<string, string> $fields فیلدهای متنی فرم
 * @param array<string, string> $files   نگاشت نام فیلد به مسیر فایل روی سرور (tmp_name)
 */
function callAPIUpload($endpoint, $fields = [], $files = [])
{
    $curl = curl_init();

    $endpoint = ltrim($endpoint, '/');
    $url = API_BASE_URL . '/' . $endpoint;

    $headers = [
        'Accept: application/json'
    ];

    if (isset($_SESSION['token']) && !empty($_SESSION['token'])) {
        $headers[] = 'Authorization: Bearer ' . $_SESSION['token'];
    }

    $postFields = $fields;
    foreach ($files as $field => $filePath) {
        if (is_string($filePath) && is_file($filePath)) {
            $mime = function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream';
            $postFields[$field] = new CURLFile($filePath, (string) $mime, basename($filePath));
        }
    }

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    // غیرفعال کردن موقت سخت‌گیری SSL برای ارتباط درون‌سروری
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);

    $result = curl_exec($curl);
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $response = json_decode(is_string($result) ? $result : '', true);
    if (!is_array($response)) {
        return [
            'success' => false,
            'message' => 'ارتباط با API برقرار نشد.',
            'http_code' => $http_status,
        ];
    }
    $response['http_code'] = $http_status;
    return $response;
}

// ---------- توابع کمکی نمایش اعداد و زمان فارسی ----------

function fa_digits($value)
{
    $map = ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'];
    return strtr((string) $value, $map);
}

/**
 * تبدیل ارقام فارسی/عربی به ارقام انگلیسی.
 * برای فیلدهای عددی فرم‌ها لازم است؛ در غیر این صورت مقادیری مثل «۲۰»
 * هنگام تبدیل به int صفر/تهی می‌شوند و ثبت نمی‌گردند.
 */
function en_digits($value)
{
    $map = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '،' => '', ',' => '',
    ];
    return trim(strtr((string) $value, $map));
}

function fa_number($value)
{
    return fa_digits(number_format((float) $value, 0, '.', ','));
}

function fa_time_ago($datetime)
{
    if (empty($datetime)) {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'همین حالا';
    }
    if ($diff < 3600) {
        return fa_digits((int) floor($diff / 60)) . ' دقیقه پیش';
    }
    if ($diff < 86400) {
        return fa_digits((int) floor($diff / 3600)) . ' ساعت پیش';
    }
    if ($diff < 172800) {
        return 'دیروز';
    }
    return fa_digits((int) floor($diff / 86400)) . ' روز پیش';
}

// ---------- نقشه وضعیت‌ها و دسته‌بندی‌ها ----------

function ticket_status_label($status)
{
    $map = ['open' => 'باز', 'in_progress' => 'در حال بررسی', 'resolved' => 'حل‌شده', 'closed' => 'بسته‌شده', 'rejected' => 'ردشده'];
    return $map[$status] ?? ($status ?: 'نامشخص');
}

function ticket_priority_label($priority)
{
    $map = ['low' => 'کم', 'normal' => 'عادی', 'high' => 'زیاد', 'urgent' => 'فوری'];
    return $map[$priority] ?? ($priority ?: 'عادی');
}

function ticket_status_color($status)
{
    $map = ['open' => 'bg-blue-100 text-blue-700', 'in_progress' => 'bg-amber-100 text-amber-700', 'resolved' => 'bg-green-100 text-green-700', 'closed' => 'bg-gray-200 text-gray-600', 'rejected' => 'bg-red-100 text-red-700'];
    return $map[$status] ?? 'bg-gray-100 text-gray-600';
}

function payment_status_label($status)
{
    $map = ['pending' => 'در انتظار', 'upload_receipt' => 'در انتظار رسید', 'confirmed' => 'تأیید شده', 'rejected' => 'رد شده'];
    return $map[$status] ?? ($status ?: 'نامشخص');
}

function payment_status_color($status)
{
    $map = ['pending' => 'bg-gray-100 text-gray-600', 'upload_receipt' => 'bg-amber-100 text-amber-700', 'confirmed' => 'bg-green-100 text-green-700', 'rejected' => 'bg-red-100 text-red-700'];
    return $map[$status] ?? 'bg-gray-100 text-gray-600';
}

function unit_type_label($type)
{
    $map = ['residential' => 'مسکونی', 'commercial' => 'تجاری', 'office' => 'اداری', 'parking' => 'پارکینگ', 'storage' => 'انباری'];
    return $map[$type] ?? ($type ?: 'نامشخص');
}

function consumption_type_label($type)
{
    $map = ['water' => 'آب', 'electricity' => 'برق', 'gas' => 'گاز'];
    return $map[$type] ?? ($type ?: 'نامشخص');
}

function maintenance_status_label($status)
{
    $map = ['pending' => 'در انتظار', 'in_progress' => 'در حال انجام', 'resolved' => 'انجام‌شده', 'closed' => 'بسته‌شده'];
    return $map[$status] ?? ($status ?: 'نامشخص');
}

function booking_status_label($status)
{
    $map = ['pending' => 'در انتظار تأیید', 'confirmed' => 'تأیید شده', 'cancelled' => 'لغو شده', 'completed' => 'انجام شده'];
    return $map[$status] ?? ($status ?: 'نامشخص');
}

function meeting_status_label($status)
{
    $map = ['scheduled' => 'برنامه‌ریزی شده', 'completed' => 'برگزار شده', 'cancelled' => 'لغو شده'];
    return $map[$status] ?? ($status ?: 'نامشخص');
}

function visitor_status_label($status)
{
    $map = ['entered' => 'داخل ساختمان', 'exited' => 'خارج شده'];
    return $map[$status] ?? ($status ?: 'نامشخص');
}

function vote_status_label($status)
{
    $map = ['active' => 'باز', 'closed' => 'بسته شده'];
    return $map[$status] ?? ($status ?: 'نامشخص');
}

function review_stars($rating)
{
    $rating = (int) $rating;
    $full = str_repeat('★', $rating);
    $empty = str_repeat('☆', max(0, 5 - $rating));
    return $full . $empty;
}

// ---------- هلپرهای شماره موبایل و کاور ساختمان ----------

/**
 * نرمال‌سازی شماره موبایل ایرانی (نام‌کاربری سیستم) در سمت فرانت.
 */
function normalize_phone($phone)
{
    $phone = (string) ($phone ?? '');
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $phone = str_replace($fa, $en, $phone);
    $phone = str_replace($ar, $en, $phone);
    $phone = preg_replace('/[^\d+]/', '', $phone);
    $phone = ltrim($phone, '+');
    if (strpos($phone, '0098') === 0) {
        $phone = substr($phone, 2);
    }
    if (strpos($phone, '98') === 0 && strlen($phone) === 12) {
        $phone = '0' . substr($phone, 2);
    }
    if (strlen($phone) === 10 && strpos($phone, '9') === 0) {
        $phone = '0' . $phone;
    }
    return $phone;
}

function is_valid_phone($phone)
{
    return (bool) preg_match('/^09\d{9}$/', normalize_phone($phone));
}

/**
 * لیست عکس‌های پیش‌فرض ساختمان (assets/img/buildings/b1..b4.jpg).
 */
function building_default_images()
{
    return [
        'b1' => 'assets/img/buildings/b1.jpg',
        'b2' => 'assets/img/buildings/b2.jpg',
        'b3' => 'assets/img/buildings/b3.jpg',
        'b4' => 'assets/img/buildings/b4.jpg',
    ];
}

/**
 * آدرس کاور ساختمان: عکس پیش‌فرض انتخاب‌شده، وگرنه لوگوی سفارشی، وگرنه b1.
 */
function building_cover($building)
{
    $images = building_default_images();
    $key = $building['default_image'] ?? '';
    if (!empty($key) && isset($images[$key]) && is_file(__DIR__ . '/../' . $images[$key])) {
        return $images[$key];
    }
    if (!empty($building['custom_logo_path'])) {
        return $building['custom_logo_path'];
    }
    return $images['b1'];
}

/**
 * نقش کاربر در ساختمان به فارسی.
 */
function member_role_label($role)
{
    $map = [
        'manager' => 'مدیر ساختمان',
        'owner' => 'مالک',
        'tenant' => 'مستأجر',
        'resident' => 'ساکن',
        'board' => 'هیئت مدیره',
        'accountant' => 'حسابدار',
    ];
    return $map[$role] ?? 'عضو';
}

?>
