<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Logger;
use App\Utilities\JalaliHelper;

// هیچ خطایی نباید بی‌صدا بماند: هشدارها، استثناهای مدیریت‌نشده و خطاهای مرگبار لاگ می‌شوند
Logger::install();

// جلسه فقط وقتی شروع می‌شود که هنوز فعال نیست و خروجی ارسال نشده باشد
// (در تست‌ها و اجرای CLI ممکن است هر دو شرط برقرار نباشد)
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/**
 * ==================== محافظت CSRF ====================
 *
 * هر درخواست POST باید توکن معتبر جلسه را همراه داشته باشد، وگرنه رد می‌شود.
 * توکن یک‌بار برای هر جلسه ساخته می‌شود و با csrf_field() داخل فرم‌ها می‌آید.
 *
 * برای فرم‌های نوشته‌شده با دست، فقط کافی است csrf_field() را صدا بزنید:
 *     <form method="POST"> <?= csrf_field() ?> ... </form>
 */

/** توکن CSRF جلسه جاری (در صورت نبود ساخته می‌شود) */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $_SESSION['csrf_token'] = hash('sha256', uniqid('csrf', true) . microtime(true));
        }
    }
    return $_SESSION['csrf_token'];
}

/** ورودی مخفی آماده برای درج در فرم */
function csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** آیا توکن ارسالی با توکن جلسه یکی است؟ */
function csrf_verify($token)
{
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $expected !== '' && hash_equals($expected, $token);
}

/**
 * اعتبارسنجی خودکار همه درخواست‌های POST.
 *
 * صفحاتی که پیش از ساخته‌شدن جلسه اجرا می‌شوند (مثل خود auth.php در گام اول)
 * هم پوشش داده می‌شوند، چون توکن به جلسه گره خورده نه به ورود کاربر.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && PHP_SAPI !== 'cli') {
    $__csrf_sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if (!csrf_verify($__csrf_sent)) {
        Logger::warning('csrf', 'درخواست POST بدون توکن معتبر رد شد', [
            'script' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')),
            'form_action' => $_POST['form_action'] ?? null,
            'has_token' => $__csrf_sent !== '',
        ]);

        // توکن تازه بساز تا کاربر بتواند دوباره تلاش کند
        unset($_SESSION['csrf_token']);
        csrf_token();

        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>نشست منقضی شد</title>'
           . '<link rel="stylesheet" href="assets/css/style.css"></head><body>'
           . '<div style="max-width:420px;margin:15vh auto;padding:24px;text-align:center;'
           . 'font-family:Vazirmatn,Tahoma,sans-serif;line-height:2">'
           . '<h2 style="margin-bottom:12px">نشست شما منقضی شده است</h2>'
           . '<p style="color:#555;margin-bottom:20px">برای امنیت حساب شما این درخواست انجام نشد. '
           . 'لطفاً به صفحه قبل برگردید و دوباره تلاش کنید.</p>'
           . '<a href="javascript:history.back()" style="display:inline-block;padding:10px 22px;'
           . 'background:#2563eb;color:#fff;border-radius:10px;text-decoration:none">بازگشت</a>'
           . '</div></body></html>';
        exit;
    }
}

// آدرس دقیق API — در حالت عادی همان سرور اصلی است.
// برای اجرای محلی می‌توانید بدون دست‌زدن به این فایل، متغیر محیطی API_BASE_URL را تنظیم کنید:
//   API_BASE_URL=http://localhost:8000/b/api php -S localhost:8080
$apiBaseUrl = getenv('API_BASE_URL');
if (!is_string($apiBaseUrl) || $apiBaseUrl === '') {
    $apiBaseUrl = 'https://file.falnic.com/b/api';
}
define('API_BASE_URL', $apiBaseUrl);

/**
 * اگر کاربر جریان ثبت‌نام را نیمه‌کاره رها کرده (نام یا رمز ندارد)،
 * او را به همان گام در صفحه ورود یکپارچه برمی‌گردانیم.
 * صفحه auth.php و logout.php از این قاعده مستثنا هستند.
 */
if (!empty($_SESSION['auth_pending']) && !empty($_SESSION['token'])) {
    $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($current, ['auth.php', 'logout.php'], true)) {
        header('Location: auth.php');
        exit;
    }
}

/**
 * تنظیم بررسی گواهی SSL روی یک هندل cURL.
 *
 * پیش‌فرض: بررسی کامل گواهی (امن). غیرفعال‌سازی فقط با تنظیم صریح
 * متغیر محیطی API_INSECURE_SSL=1 ممکن است و در محیط تولید نادیده گرفته می‌شود.
 */
function api_apply_ssl_options($curl)
{
    $insecure = getenv('API_INSECURE_SSL') === '1';

    if ($insecure && \App\Config\AppConfig::isProduction()) {
        Logger::warning('callAPI', 'API_INSECURE_SSL در محیط تولید نادیده گرفته شد');
        $insecure = false;
    }

    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $insecure ? 0 : 2);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $insecure ? 0 : true);
}

function callAPI($method, $endpoint, $data = false) {
    // کش scoped به درخواست: GETهای صرفاً خواندنی که در یک صفحه چندبار
    // تکرار می‌شوند، فقط یک‌بار دیسپچ می‌شوند (کاهش رفت‌وآمد کرنل/شبکه).
    // فهرست سفید: داده‌هایی که در طول یک درخواست تغییر نمی‌کنند.
    static $__get_cache = [];
    $normalized = '/' . ltrim((string) $endpoint, '/');
    $cache_key = null;
    if (strtoupper((string) $method) === 'GET'
        && preg_match('#^/(auth/me|buildings/\d+/members|buildings/\d+/units|messages/unread-count)$#', $normalized)) {
        $cache_key = $normalized . '|' . md5(json_encode($data ?: []));
        if (array_key_exists($cache_key, $__get_cache)) {
            return $__get_cache[$cache_key];
        }
    }

    $response = callAPI_dispatch($method, $endpoint, $data);
    if ($cache_key !== null) {
        $__get_cache[$cache_key] = $response;
    }
    return $response;
}

/** دیسپچ واقعی درخواست (داخلی در حالت تست، وگرنه curl) */
function callAPI_dispatch($method, $endpoint, $data = false) {
    // حالت تست/‏E2E: به‌جای HTTP، درخواست در همان فرایند از مسیر واقعی
    // کرنل (میدل‌ورها → روتر → کنترلر → سرویس) عبور می‌کند.
    if (defined('API_INTERNAL_DISPATCH') && API_INTERNAL_DISPATCH === true) {
        return api_internal_dispatch($method, $endpoint, $data);
    }

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
    
    // بررسی گواهی SSL به‌صورت پیش‌فرض فعال است.
    // فقط برای توسعه محلی با گواهی خودامضا می‌توان API_INSECURE_SSL=1 گذاشت.
    api_apply_ssl_options($curl);
    
    $started = microtime(true);
    $result = curl_exec($curl);
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_errno = curl_errno($curl);
    $curl_error = curl_error($curl);
    curl_close($curl);

    $took = (int) round((microtime(true) - $started) * 1000);
    $log_ctx = [
        'method' => strtoupper($method),
        'endpoint' => $endpoint,
        'http_code' => $http_status,
        'duration_ms' => $took,
    ];

    // خطای شبکه/ترنسپورت: اصلاً به سرور نرسیدیم
    if ($curl_errno !== 0) {
        Logger::error('callAPI', 'ارتباط شبکه‌ای با API برقرار نشد', $log_ctx + [
            'curl_errno' => $curl_errno,
            'curl_error' => $curl_error,
        ]);
        return [
            'success' => false,
            'message' => 'ارتباط با API برقرار نشد.',
            'raw_error' => htmlspecialchars(substr($curl_error, 0, 250)),
            'http_code' => $http_status,
        ];
    }

    $result_string = is_string($result) ? $result : '';
    $response = json_decode($result_string, true);

    // پاسخ JSON نبود: معمولاً یعنی صفحه خطای PHP یا HTML برگشته
    if (!is_array($response)) {
        Logger::error('callAPI', 'پاسخ API قابل تفسیر نبود (JSON نیست)', $log_ctx + [
            'body_preview' => substr($result_string, 0, 500),
        ]);
        return [
            'success' => false,
            'message' => 'ارتباط با API برقرار نشد.',
            'raw_error' => htmlspecialchars(substr($result_string, 0, 250)),
            'http_code' => $http_status
        ];
    }

    // پاسخ معتبر ولی ناموفق: سطح لاگ بسته به نوع خطا
    if ($http_status >= 500) {
        Logger::error('callAPI', 'API خطای سرور برگرداند', $log_ctx + [
            'api_message' => $response['message'] ?? null,
        ]);
    } elseif ($http_status >= 400) {
        Logger::warning('callAPI', 'درخواست API رد شد', $log_ctx + [
            'api_message' => $response['message'] ?? null,
        ]);
    } elseif ($took > 3000) {
        Logger::warning('callAPI', 'پاسخ API کند بود', $log_ctx);
    }

    $response['http_code'] = $http_status;
    return $response;
}

/**
 * دیسپچ داخلی درخواست در همان فرایند (فقط برای تست‌های یکپارچه و E2E).
 * همهٔ مراحل واقعی را طی می‌کند: میدل‌ورها (احراز هویت، نرخ، کش) → روتر → کنترلر → سرویس.
 *
 * @return array پاسخ جی‌سان + کد وضعیت
 */
/** ساخت (یا بازیابی) کرنل مشترک برای دیسپچ داخلی */
function api_internal_kernel(): \App\Core\Kernel
{
    static $kernel = null;
    if ($kernel === null) {
        // کرنل برای مسیریابی به این دو فایل نیاز دارد (در حالت عادی توسط public/index.php لود می‌شوند)
        require_once dirname(__DIR__) . '/config/app.php';
        require_once dirname(__DIR__) . '/config/routes.php';
        $kernel = new \App\Core\Kernel();
    }
    return $kernel;
}

function api_internal_dispatch($method, $endpoint, $data = false): array
{
    $kernel = api_internal_kernel();

    $method = strtoupper((string) $method);
    $uri = '/api/' . ltrim((string) $endpoint, '/');
    $body = null;
    if ($method === 'GET' && $data) {
        $uri .= '?' . http_build_query($data);
    } elseif ($data) {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    $headers = [
        'content-type' => 'application/json',
        'accept' => 'application/json',
    ];
    if (!empty($_SESSION['token'])) {
        $headers['authorization'] = 'Bearer ' . $_SESSION['token'];
    }

    $request = new \App\Core\Request();
    $ref = new ReflectionClass($request);
    // شبیه‌سازی دقیق معنای HTTP: پارامترهای کوئری همیشه رشته می‌رسند.
    // بدون این تبدیل، مقدار عددی (مثلاً building_id از صفحه‌ها) در
    // حالت داخلی به Request تزریق می‌شد و خطای نوع می‌ساخت.
    $query = [];
    if ($method === 'GET' && is_array($data)) {
        foreach ($data as $qk => $qv) {
            if (is_bool($qv)) {
                $query[$qk] = $qv ? '1' : '0';
            } elseif (is_scalar($qv) || $qv === null) {
                $query[$qk] = $qv === null ? '' : (string) $qv;
            }
        }
    }

    foreach ([
        'method' => $method,
        'uri' => $uri,
        'query' => $query,
        'post' => ($method !== 'GET' && is_array($data)) ? $data : [],
        'headers' => $headers,
        'body' => $body,
    ] as $prop => $value) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($request, $value);
    }

    try {
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getContent(), true);
        if (!is_array($decoded)) {
            $decoded = ['success' => false, 'message' => 'پاسخ داخلی قابل تفسیر نبود.'];
        }
        $decoded['http_code'] = $status;
        return $decoded;
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => 'خطای داخلی: ' . $e->getMessage(),
            'http_code' => 500,
        ];
    }
}

/**
 * ارسال درخواست POST با فرم چندبخشی (آپلود فایل).
 *
 * @param string $endpoint
 * @param array<string, string> $fields فیلدهای متنی فرم
 * @param array<string, string> $files   نگاشت نام فیلد به مسیر فایل روی سرور (tmp_name)
 */
/**
 * نرمال‌سازی مشخصهٔ فایل برای آپلود.
 * ورودی می‌تواند رشته (مسیر) یا آرایهٔ ['path' => ..., 'name' => نام اصلی] باشد.
 *
 * @return array{0: ?string, 1: ?string} [مسیر فایل، نام اصلی]
 */
function api_upload_spec($spec): array
{
    if (is_string($spec)) {
        return [$spec, null];
    }
    if (is_array($spec)) {
        $path = isset($spec['path']) && is_string($spec['path']) ? $spec['path'] : null;
        $name = isset($spec['name']) && is_string($spec['name']) && $spec['name'] !== '' ? $spec['name'] : null;
        return [$path, $name];
    }
    return [null, null];
}

function callAPIUpload($endpoint, $fields = [], $files = [])
{
    // حالت تست/E2E: آپلود چندبخشی هم از مسیر واقعی کرنل عبور می‌کند
    if (defined('API_INTERNAL_DISPATCH') && API_INTERNAL_DISPATCH === true) {
        return api_internal_dispatch_upload($endpoint, $fields, $files);
    }

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
    foreach ($files as $field => $fileSpec) {
        [$filePath, $originalName] = api_upload_spec($fileSpec);
        if ($filePath !== null && is_file($filePath)) {
            $mime = function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream';
            $postFields[$field] = new CURLFile($filePath, (string) $mime, $originalName ?? basename($filePath));
        }
    }

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    // بررسی گواهی SSL به‌صورت پیش‌فرض فعال است.
    // فقط برای توسعه محلی با گواهی خودامضا می‌توان API_INSECURE_SSL=1 گذاشت.
    api_apply_ssl_options($curl);

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

/**
 * دیسپچ داخلی درخواست چندبخشی (آپلود فایل) برای حالت تست.
 * فایل‌ها با ساختار استاندارد $_شبیه‌سازی و از مسیر واقعی کرنل عبور می‌کنند.
 *
 * @param array<string, string> $files نگاشت نام فیلد به مسیر فایل روی دیسک
 */
function api_internal_dispatch_upload($endpoint, array $fields = [], array $files = []): array
{
    $kernel = api_internal_kernel();

    $filesSuper = [];
    foreach ($files as $field => $fileSpec) {
        [$filePath, $originalName] = api_upload_spec($fileSpec);
        if ($filePath !== null && is_file($filePath)) {
            $mime = function_exists('mime_content_type') ? (mime_content_type($filePath) ?: 'application/octet-stream') : 'application/octet-stream';
            $filesSuper[$field] = [
                'name' => $originalName ?? basename($filePath),
                'type' => $mime,
                'tmp_name' => $filePath,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($filePath),
            ];
        }
    }

    $headers = [
        'content-type' => 'multipart/form-data',
        'accept' => 'application/json',
    ];
    if (!empty($_SESSION['token'])) {
        $headers['authorization'] = 'Bearer ' . $_SESSION['token'];
    }

    $request = new \App\Core\Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => 'POST',
        'uri' => '/api/' . ltrim((string) $endpoint, '/'),
        'query' => [],
        'post' => $fields,
        'headers' => $headers,
        'body' => null,
        'files' => $filesSuper,
    ] as $prop => $value) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($request, $value);
    }

    try {
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getContent(), true);
        if (!is_array($decoded)) {
            $decoded = ['success' => false, 'message' => 'پاسخ داخلی قابل تفسیر نبود.'];
        }
        $decoded['http_code'] = $status;
        return $decoded;
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => 'خطای داخلی: ' . $e->getMessage(),
            'http_code' => 500,
        ];
    }
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

/**
 * نمایش زمان هوشمند و فشرده برای فهرست‌ها:
 * امروز → «امروز ۱۴:۳۰» — دیروز → «دیروز» — تا یک هفته → «۳ روز پیش»
 * قدیمی‌تر → تاریخ شمسی (با سال در صورت تغییر سال)
 */
function fa_smart_time($datetime)
{
    $ts = strtotime((string) $datetime);
    if ($ts === false) {
        return '';
    }
    $today = strtotime('today');
    if ($ts >= $today) {
        return 'امروز ' . fa_digits(date('H:i', $ts));
    }
    if ($ts >= $today - 86400) {
        return 'دیروز';
    }
    $days = (int) floor(($today - $ts) / 86400);
    if ($days <= 6) {
        return fa_digits($days) . ' روز پیش';
    }
    $year = jdate('Y', $ts);
    if ($year !== jdate('Y')) {
        return fa_digits(JalaliHelper::format('j F Y', $ts));
    }
    return fa_digits(JalaliHelper::format('j F', $ts));
}

/**
 * برچسب گروه‌بندی روزانه برای فهرست‌ها: امروز / دیروز / «۱۵ شهریور ۱۴۰۵»
 * خروجی برای یک روز معین همیشه یکسان است تا بتوان مرز گروه‌ها را تشخیص داد.
 */
function fa_day_label($datetime)
{
    $ts = strtotime((string) $datetime);
    if ($ts === false) {
        return '';
    }
    $today = strtotime('today');
    $startOfDay = $today;
    if ($ts >= $startOfDay) {
        return 'امروز';
    }
    if ($ts >= $startOfDay - 86400) {
        return 'دیروز';
    }
    return fa_digits(JalaliHelper::format('j F Y', $ts));
}

// ---------- تقویم جلالی (شمسی) ----------
// پیاده‌سازی اصلی در کلاس مشترک \App\Utilities\JalaliHelper است
// تا اسکریپت‌های سمت سرور (مثل یادآوری‌ها) و فرانت‌اند هر دو یک منطق داشته باشند.

/** تبدیل میلادی به جلالی — خروجی: [سال، ماه، روز] */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    return JalaliHelper::toJalali($gy, $gm, $gd);
}

/** تبدیل جلالی به میلادی — خروجی: [سال، ماه، روز] */
function jalali_to_gregorian(int $jy, int $jm, int $jd): array
{
    return JalaliHelper::toGregorian($jy, $jm, $jd);
}

/** تعداد روزهای ماه جلالی (اسفند: ۲۹ یا ۳۰) */
function jalali_month_length(int $jy, int $jm): int
{
    return JalaliHelper::monthLength($jy, $jm);
}

/** نام ماه جلالی (۱ تا ۱۲) */
function jalali_month_name(int $jm): string
{
    return JalaliHelper::MONTH_NAMES[$jm] ?? '';
}

/** قالب‌بندی تاریخ جلالی — توکن‌ها: Y, m, d, j, F, l */
function jdate(string $format, ?int $timestamp = null): string
{
    return JalaliHelper::format($format, $timestamp ?? time());
}

/** شاخص روز هفته شنبه‌محور: شنبه=۰ ... جمعه=۶ */
function jalali_weekday_index(int $timestamp): int
{
    return JalaliHelper::weekDayIndex($timestamp);
}

/**
 * نمایش تاریخ شمسی یک مقدار تاریخ/زمان: «۱۵ شهریور ۱۴۰۵»
 * ورودی تهی یا نامعتبر رشته خالی برمی‌گرداند.
 */
function fa_date($datetime)
{
    $ts = strtotime((string) $datetime);
    if ($ts === false) {
        return '';
    }
    return fa_digits(JalaliHelper::format('j F Y', $ts));
}

/**
 * نمایش تاریخ + ساعت شمسی: «۱۵ شهریور ۱۴۰۵، ساعت ۱۸:۳۰»
 * اگر مقدار ورودی ساعت نداشته باشد (فقط تاریخ)، بخش ساعت حذف می‌شود.
 */
function fa_datetime($datetime)
{
    $raw = (string) $datetime;
    $ts = strtotime($raw);
    if ($ts === false) {
        return '';
    }
    $has_time = preg_match('/[:T]|\d{4}-\d{2}-\d{2} \d/', $raw) === 1;
    $date = fa_digits(JalaliHelper::format('j F Y', $ts));
    return $has_time ? $date . '، ساعت ' . fa_digits(date('H:i', $ts)) : $date;
}

/**
 * برچسب فاصله روزی نسبت به امروز: امروز / فردا / پس‌فردا / ۳ روز دیگر
 * ورودی: تاریخ رویداد (هر فرمت قابل‌فهم با strtotime).
 */
function fa_days_until($datetime)
{
    $ts = strtotime((string) $datetime);
    if ($ts === false) {
        return '';
    }
    $days = (int) floor((strtotime(date('Y-m-d', $ts)) - strtotime(date('Y-m-d'))) / 86400);
    return match (true) {
        $days <= 0 => 'امروز',
        $days === 1 => 'فردا',
        $days === 2 => 'پس‌فردا',
        default => fa_digits($days) . ' روز دیگر',
    };
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


/**
 * زمینه نقش کاربر جاری در یک ساختمان.
 *
 * خروجی: آرایه‌ای شامل
 *   user_id        شناسه کاربر جاری
 *   role           manager | owner | tenant | resident | board
 *   role_label     برچسب فارسی نقش
 *   is_manager     مدیر ساختمان؟
 *   is_owner       مالک؟
 *   is_tenant      مستأجر؟
 *   is_resident    آیا این کاربر واقعاً در ساختمان ساکن است؟
 *   units          واحدهایی که کاربر با آن‌ها در ارتباط است
 *
 * نتیجه در طول یک درخواست کش می‌شود تا API چند بار صدا زده نشود.
 */
function building_role_context($building_id)
{
    static $cache = [];
    $building_id = (int) $building_id;
    if (isset($cache[$building_id])) {
        return $cache[$building_id];
    }

    $ctx = [
        'user_id' => 0,
        'role' => 'resident',
        'role_label' => 'عضو',
        'is_manager' => false,
        'is_owner' => false,
        'is_tenant' => false,
        'is_resident' => false,
        'units' => [],
    ];

    $raw_units = [];

    $me = callAPI('GET', '/auth/me');
    if (!empty($me['success'])) {
        $ctx['user_id'] = (int) ($me['data']['id'] ?? 0);
    }

    if ($building_id > 0 && $ctx['user_id'] > 0) {
        $members = callAPI('GET', '/buildings/' . $building_id . '/members');
        if (!empty($members['success'])) {
            foreach (($members['data'] ?? []) as $m) {
                if ((int) ($m['user_id'] ?? 0) === $ctx['user_id']) {
                    $ctx['role'] = $m['role'] ?? 'resident';
                    break;
                }
            }
        }

        // تعیین مالک/مستأجر/ساکن بودن از روی واحدها (منبع حقیقت واقعی)
        $units_resp = callAPI('GET', '/buildings/' . $building_id . '/units');
        if (!empty($units_resp['success'])) {
            $raw_units = $units_resp['data']['units'] ?? [];
        }
    }

    $ctx = derive_role_context($ctx['user_id'], $ctx['role'], $raw_units);

    $cache[$building_id] = $ctx;
    return $ctx;
}

/**
 * منطق خالص تعیین نقش و سکونت — بدون تماس با API تا قابل تست باشد.
 *
 * قواعد:
 *   • مدیر ساختمان با نقش عضویت manager مشخص می‌شود.
 *   • مالک: در واحدی owner_user_id او باشد، یا نقش عضویتش owner باشد.
 *   • مستأجر: در واحدی tenant_user_id او باشد، یا نقش عضویتش tenant باشد.
 *   • مستأجر همیشه ساکن است؛ مالک فقط وقتی owner_resident واحد فعال باشد.
 *
 * @param int    $user_id شناسه کاربر
 * @param string $role    نقش عضویت در ساختمان
 * @param array  $units   فهرست واحدهای ساختمان
 * @return array زمینه نقش
 */
function derive_role_context($user_id, $role, array $units = [])
{
    $user_id = (int) $user_id;
    $role = (string) ($role ?: 'resident');

    $ctx = [
        'user_id' => $user_id,
        'role' => $role,
        'role_label' => member_role_label($role),
        'is_manager' => ($role === 'manager'),
        'is_owner' => false,
        'is_tenant' => false,
        'is_resident' => false,
        'units' => [],
    ];

    if ($user_id > 0) {
        foreach ($units as $u) {
            $is_owner = (int) ($u['owner_user_id'] ?? 0) === $user_id;
            $is_tenant = (int) ($u['tenant_user_id'] ?? 0) === $user_id;
            if (!$is_owner && !$is_tenant) {
                continue;
            }
            $ctx['units'][] = $u;
            if ($is_owner) {
                $ctx['is_owner'] = true;
                // مالک وقتی ساکن است که owner_resident فعال باشد
                if (!empty($u['owner_resident'])) {
                    $ctx['is_resident'] = true;
                }
            }
            if ($is_tenant) {
                // مستأجر همیشه ساکن واحد است
                $ctx['is_tenant'] = true;
                $ctx['is_resident'] = true;
            }
        }
    }

    // اگر نقش عضویت صراحتاً مالک/مستأجر بود ولی واحدی ثبت نشده، همان را لحاظ کن
    if (!$ctx['is_owner'] && $role === 'owner') {
        $ctx['is_owner'] = true;
    }
    if (!$ctx['is_tenant'] && $role === 'tenant') {
        $ctx['is_tenant'] = true;
        $ctx['is_resident'] = true;
    }

    return $ctx;
}

/**
 * برچسب وضعیت سکونت یک واحد.
 */
function occupancy_label($unit)
{
    $status = $unit['occupancy_status'] ?? '';
    $map = [
        'owner_occupied' => 'مالک ساکن است',
        'tenant_occupied' => 'مستأجر ساکن است',
        'vacant' => 'خالی',
        'no_owner' => 'بدون مالک',
    ];
    return $map[$status] ?? 'نامشخص';
}

?>
