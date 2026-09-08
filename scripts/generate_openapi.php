<?php
/**
 * ==================== مولد خروجی OpenAPI ====================
 * مشخصات کامل REST API را از جدول روت‌ها (config/routes.php) می‌خواند و
 * فایل استاندارد OpenAPI 3.0 را در docs/openapi.json تولید می‌کند.
 *
 * اجرا (روی سرور توسعه):
 *   php scripts/generate_openapi.php
 *
 * این فایل در سوئیت تست «openapi» با جدول روت‌ها همگام‌سنجی می‌شود؛
 * پس از هر تغییر روت، دوباره اجرا شود.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/routes.php';

use App\Config\Routes;

// ------------------------------------------------------------------
// برچسب فارسی ماژول‌ها (برای تگ‌ها)
// ------------------------------------------------------------------
$tagLabels = [
    'auth' => 'احراز هویت',
    'buildings' => 'ساختمان‌ها',
    'blocks' => 'بلوک‌ها',
    'floors' => 'طبقات',
    'units' => 'واحدها',
    'common-areas' => 'مشاعات',
    'members' => 'اعضا',
    'invitations' => 'دعوت‌نامه‌ها',
    'bulk-users' => 'ساخت گروهی کاربران',
    'tickets' => 'تیکت‌ها',
    'notifications' => 'اعلانات',
    'costs' => 'هزینه‌ها',
    'payments' => 'پرداخت‌ها',
    'messages' => 'پیام‌های خصوصی',
    'audit-logs' => 'لاگ اقدامات',
    'penalty-settings' => 'تنظیمات جریمه',
    'meetings' => 'جلسات',
    'bookings' => 'رزرو مشاعات',
    'visitors' => 'مهمان‌ها',
    'maintenance' => 'تعمیرات',
    'documents' => 'اسناد',
    'votes' => 'رأی‌گیری‌ها',
    'reviews' => 'نظرات',
    'consumption' => 'مصرف انرژی',
    'emergency-contacts' => 'شماره‌های اضطراری',
    'announcements' => 'اعلانیه‌ها',
];

// ------------------------------------------------------------------
// شرح فارسی اندپوینت‌های مهم (سایر موارد شرح خودکار می‌گیرند)
// ------------------------------------------------------------------
$summaries = [
    'POST /api/auth/register' => 'ثبت‌نام کاربر جدید',
    'POST /api/auth/login' => 'ورود با رمز عبور',
    'POST /api/auth/check-phone' => 'بررسی وضعیت شماره موبایل (ثبت‌نام/ورود)',
    'POST /api/auth/send-otp' => 'ارسال کد یک‌بارمصرف پیامکی',
    'POST /api/auth/verify-otp' => 'تأیید کد یک‌بارمصرف و دریافت توکن',
    'POST /api/auth/complete-name' => 'تکمیل نام کاربر تازه‌ثبت‌نام‌شده',
    'POST /api/auth/set-password' => 'تعیین رمز عبور',
    'POST /api/auth/refresh' => 'تازه‌سازی توکن نشست',
    'POST /api/auth/logout' => 'خروج از نشست',
    'GET /api/auth/me' => 'مشخصات کاربر جاری',
    'PUT /api/auth/me' => 'ویرایش مشخصات کاربر جاری',
    'PUT /api/auth/password' => 'تغییر رمز عبور',

    'GET /api/buildings' => 'فهرست ساختمان‌های کاربر',
    'POST /api/buildings' => 'ساخت ساختمان جدید (با اسکلت‌بندی طبقات و واحدها)',
    'GET /api/buildings/{id}' => 'مشاهده یک ساختمان',
    'PUT /api/buildings/{id}' => 'ویرایش ساختمان',
    'DELETE /api/buildings/{id}' => 'حذف ساختمان (فقط مدیر)',

    'GET /api/buildings/{building_id}/members' => 'اعضای ساختمان با واحدهای هر عضو',
    'POST /api/buildings/{building_id}/invitations' => 'ارسال دعوت‌نامه عضویت',
    'GET /api/buildings/{building_id}/invitations' => 'فهرست دعوت‌نامه‌های ساختمان',
    'POST /api/buildings/{building_id}/bulk-users' => 'ساخت گروهی کاربران و انتساب به واحدها',
    'POST /api/invitations/accept' => 'پذیرش دعوت‌نامه با توکن',
    'POST /api/invitations/{id}/resend' => 'ارسال مجدد دعوت‌نامه',
    'DELETE /api/invitations/{id}' => 'حذف دعوت‌نامه',
    'GET /api/invitations/info' => 'اطلاعات دعوت‌نامه از روی توکن',

    'GET /api/tickets' => 'فهرست تیکت‌های ساختمان',
    'POST /api/tickets' => 'ثبت تیکت جدید',
    'GET /api/tickets/{id}' => 'مشاهده یک تیکت',
    'PUT /api/tickets/{id}' => 'ویرایش تیکت',
    'DELETE /api/tickets/{id}' => 'حذف تیکت',
    'PUT /api/tickets/{id}/status' => 'تغییر وضعیت تیکت',
    'POST /api/tickets/{ticket_id}/comments' => 'ثبت نظر روی تیکت',
    'GET /api/tickets/{id}/comments' => 'نظرات یک تیکت',

    'GET /api/notifications' => 'اعلانات کاربر جاری',
    'POST /api/notifications' => 'ایجاد اعلان',
    'POST /api/notifications/{id}/read' => 'علامت‌گذاری اعلان به‌عنوان خوانده‌شده',

    'GET /api/costs' => 'فهرست هزینه‌های ساختمان',
    'GET /api/costs/summary' => 'خلاصه مالی ساختمان (صدر واحد مانده‌ها)',
    'POST /api/costs' => 'ثبت هزینه جدید',
    'POST /api/costs/monthly-charge' => 'صدور شارژ ماهیانه',
    'GET /api/costs/charge-preview' => 'پیش‌نمایش صدور شارژ',
    'POST /api/costs/{id}/issue' => 'صدور سهم هزینه برای مخاطبان',
    'PUT /api/costs/{id}' => 'ویرایش هزینه',
    'DELETE /api/costs/{id}' => 'حذف هزینه (فقط مدیر)',

    'GET /api/payments' => 'پرداخت‌های ساختمان',
    'POST /api/payments/submit' => 'ثبت پرداخت و آپلود رسید توسط ساکن',
    'POST /api/payments/{payment_id}/upload-receipt' => 'بارگذاری رسید پرداخت',
    'POST /api/payments/{payment_id}/confirm' => 'تأیید پرداخت توسط مدیر (نشست به حساب واحد)',
    'POST /api/payments/{payment_id}/reject' => 'رد پرداخت توسط مدیر با دلیل',
    'GET /api/buildings/{building_id}/unit-balances' => 'مانده بدهکار/طلبکار واحدها',
    'GET /api/buildings/{building_id}/ledger' => 'لجر کامل تراکنش‌های واحدها',
    'GET /api/buildings/{building_id}/monthly-report' => 'ریز مانده‌ها به تفکیک ماه شمسی',
    'POST /api/buildings/{building_id}/direct-payments' => 'ثبت پرداخت مستقیم برای واحد (مدیر)',
    'POST /api/buildings/{building_id}/unit-charges' => 'ثبت بدهی مستقیم برای واحد (مدیر)',
    'POST /api/buildings/{building_id}/recurring-generate' => 'اجرای موتور هزینه‌های دوره‌ای',

    'GET /api/messages/conversations' => 'فهرست گفتگوهای خصوصی کاربر در ساختمان',
    'GET /api/messages/thread/{peer_id}' => 'رشته گفتگو با یک عضو (خوانده‌شدن خودکار)',
    'GET /api/messages/unread-count' => 'تعداد پیام‌های نخوانده',
    'POST /api/messages' => 'ارسال پیام خصوصی جدید',

    'GET /api/audit-logs' => 'لاگ اقدامات کاربران (فقط مدیر)',
    'POST /api/penalty-settings' => 'ایجاد تنظیم جریمه تأخیر',
    'GET /api/penalty-settings' => 'فهرست تنظیمات جریمه',
    'PUT /api/penalty-settings/{id}' => 'ویرایش تنظیم جریمه',
    'DELETE /api/penalty-settings/{id}' => 'حذف تنظیم جریمه',
];

// ------------------------------------------------------------------
// ساخت خروجی
// ------------------------------------------------------------------
$paths = [];
$tagUsed = [];

foreach (Routes::$routes as $route => $handler) {
    [$method, $fullPath] = explode(' ', $route, 2);
    $method = strtolower($method);
    // پیشوند /api در بخش «سرورها» آمده؛ مسیرها بدون آن ثبت می‌شوند
    $path = preg_replace('#^/api#', '', $fullPath);

    // تگ از اولین سگمنت مسیر
    $firstSegment = trim(explode('/', ltrim($path, '/'))[0] ?? '');
    $tag = $tagLabels[$firstSegment] ?? $firstSegment;
    $tagUsed[$tag] = true;

    // پارامترهای مسیر
    $parameters = [];
    if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $path, $m)) {
        foreach ($m[1] as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer'],
            ];
        }
    }
    // ساختمان‌محورها یک کوئری ساختمان هم دارند
    if (str_contains($path, 'building_id') || in_array($firstSegment, ['tickets', 'notifications', 'costs', 'payments', 'messages', 'audit-logs', 'penalty-settings'], true)) {
        $parameters[] = [
            'name' => 'building_id',
            'in' => 'query',
            'required' => false,
            'schema' => ['type' => 'integer'],
        ];
    }

    $summary = $summaries[$route] ?? ($method === 'get' ? 'دریافت ' : '') . $fullPath;

    $operation = [
        'tags' => [$tag],
        'summary' => $summary,
        'responses' => [
            '200' => [
                'description' => 'پاسخ موفق',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiResponse']]],
            ],
            '401' => ['description' => 'نیازمند احراز هویت', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiError']]]],
        ],
    ];
    if ($parameters !== []) {
        $operation['parameters'] = $parameters;
    }
    if (in_array($method, ['post', 'put'], true)) {
        $operation['requestBody'] = [
            'required' => false,
            'content' => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]],
        ];
        $operation['security'] = [['bearerAuth' => []]];
    }
    if ($method === 'get' && !str_contains($path, '/auth/')) {
        $operation['security'] = [['bearerAuth' => []]];
    }

    $paths[$path][$method] = $operation;
}

ksort($paths);

$spec = [
    'openapi' => '3.0.3',
    'info' => [
        'title' => 'سامانه مدیریت ساختمان — REST API',
        'description' => "مستندات خودکار اندپوینت‌های سامانه (مولد: scripts/generate_openapi.php).\n"
            . 'همهٔ اندپوینت‌ها (به‌جز ورود/ثبت‌نام) نیازمند توکن JWT در هدر `Authorization: Bearer <token>` هستند.',
        'version' => '1.0.0',
        'license' => ['name' => 'MIT'],
    ],
    'servers' => [
        ['url' => '/api', 'description' => 'مسیر پایهٔ اپ (زیرپوشهٔ /b روی میزبانی اشتراکی)'],
    ],
    'tags' => array_map(
        static fn(string $name): array => ['name' => $name],
        array_keys($tagUsed)
    ),
    'paths' => $paths,
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
            ],
        ],
        'schemas' => [
            'ApiResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'message' => ['type' => 'string'],
                    'data' => ['description' => 'دادهٔ پاسخ — ساختار بسته به اندپوینت'],
                ],
                'required' => ['success'],
            ],
            'ApiError' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'message' => ['type' => 'string', 'example' => 'Authentication required'],
                ],
                'required' => ['success', 'message'],
            ],
        ],
    ],
];

$out = __DIR__ . '/../docs/openapi.json';
file_put_contents(
    $out,
    json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

echo 'OpenAPI generated: ' . realpath($out) . PHP_EOL
    . 'paths: ' . count($paths)
    . ' | operations: ' . array_sum(array_map('count', $paths))
    . ' | tags: ' . count($tagUsed)
    . PHP_EOL;
exit(0);
