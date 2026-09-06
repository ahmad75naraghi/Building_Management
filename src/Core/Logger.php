<?php

declare(strict_types=1);

namespace App\Core;

/**
 * لاگ‌گیر سبک و بدون وابستگی برای کل برنامه.
 *
 * هدف: هر بخش از سامانه بتواند مشکلات خود را گزارش کند، بدون آنکه اجرای صفحه
 * متوقف شود. خروجی به صورت خط‌های JSON در storage/logs/app-YYYY-MM-DD.log
 * نوشته می‌شود و در صورت غیرقابل‌نوشتن بودن مسیر، به error_log سیستم برمی‌گردد.
 *
 * نمونه استفاده:
 *   Logger::error('CostService', 'محاسبه شارژ شکست خورد', ['building_id' => 12], $e);
 *   Logger::exception('AuthController', $e, ['phone' => $phone]);
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';

    /** ترتیب اهمیت سطوح؛ برای فیلتر کردن بر اساس حداقل سطح */
    private const LEVEL_WEIGHT = [
        self::DEBUG => 10,
        self::INFO => 20,
        self::WARNING => 30,
        self::ERROR => 40,
        self::CRITICAL => 50,
    ];

    /** کلیدهایی که هرگز نباید مقدارشان در لاگ بنشیند */
    private const SENSITIVE_KEYS = [
        'password', 'password_hash', 'password_confirmation', 'current_password',
        'new_password', 'token', 'refresh_token', 'jwt', 'authorization',
        'code', 'otp', 'code_hash', 'secret', 'api_key',
    ];

    /** حافظهٔ درون‌درخواستی رویدادها (برای تست و صفحهٔ سلامت) */
    /** @var list<array<string,mixed>> */
    private static array $buffer = [];

    /** اگر true باشد چیزی روی دیسک نوشته نمی‌شود (حالت تست) */
    private static bool $memoryOnly = false;

    private static ?string $minLevel = null;

    // ------------------------------------------------------------ میانبرها

    public static function debug(string $channel, string $message, array $context = []): void
    {
        self::log(self::DEBUG, $channel, $message, $context);
    }

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::log(self::INFO, $channel, $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::log(self::WARNING, $channel, $message, $context);
    }

    public static function error(string $channel, string $message, array $context = [], ?\Throwable $e = null): void
    {
        self::log(self::ERROR, $channel, $message, self::withThrowable($context, $e));
    }

    public static function critical(string $channel, string $message, array $context = [], ?\Throwable $e = null): void
    {
        self::log(self::CRITICAL, $channel, $message, self::withThrowable($context, $e));
    }

    /** ثبت یک استثنا با پیام و ردپای خودش */
    public static function exception(string $channel, \Throwable $e, array $context = [], string $level = self::ERROR): void
    {
        self::log($level, $channel, $e->getMessage(), self::withThrowable($context, $e));
    }

    /**
     * اجرای امن یک عملیات جانبی: اگر شکست خورد، فقط لاگ می‌شود و برنامه ادامه می‌یابد.
     *
     * @template T
     * @param callable():T $fn
     * @param T|null $fallback
     * @return T|null
     */
    public static function guard(string $channel, string $what, callable $fn, mixed $fallback = null, array $context = []): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            self::error($channel, $what . ' شکست خورد', $context, $e);
            return $fallback;
        }
    }

    // ------------------------------------------------------------ هستهٔ لاگ

    public static function log(string $level, string $channel, string $message, array $context = []): void
    {
        if (!isset(self::LEVEL_WEIGHT[$level])) {
            $level = self::INFO;
        }
        if (self::LEVEL_WEIGHT[$level] < self::LEVEL_WEIGHT[self::minLevel()]) {
            return;
        }

        $record = [
            'time' => date('c'),
            'level' => $level,
            'channel' => $channel,
            'message' => self::truncate($message, 2000),
            'context' => self::sanitize($context),
            'request' => self::requestInfo(),
        ];

        self::$buffer[] = $record;
        if (count(self::$buffer) > 500) {
            array_shift(self::$buffer);
        }

        if (self::$memoryOnly) {
            return;
        }

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            $line = json_encode(['time' => $record['time'], 'level' => $level, 'channel' => $channel,
                'message' => $record['message'], 'context' => '[unserializable]']);
        }

        if (!self::write((string) $line)) {
            // آخرین سنگر: لاگ پیش‌فرض PHP
            error_log("[{$channel}][{$level}] {$record['message']}");
        }
    }

    // ------------------------------------------------------------ کمکی‌ها

    /** نوشتن روی فایل روزانه؛ در صورت شکست false برمی‌گرداند */
    private static function write(string $line): bool
    {
        $dir = self::directory();
        if ($dir === null) {
            return false;
        }
        $file = $dir . '/app-' . date('Y-m-d') . '.log';
        return @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    /** مسیر پوشهٔ لاگ؛ در صورت نبود ساخته می‌شود */
    public static function directory(): ?string
    {
        $dir = getenv('APP_LOG_DIR') ?: (dirname(__DIR__, 2) . '/storage/logs');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        return is_writable($dir) ? $dir : null;
    }

    /** حداقل سطح لاگ‌گیری؛ با متغیر محیطی APP_LOG_LEVEL قابل تنظیم است */
    private static function minLevel(): string
    {
        if (self::$minLevel === null) {
            $env = strtolower((string) (getenv('APP_LOG_LEVEL') ?: ''));
            self::$minLevel = isset(self::LEVEL_WEIGHT[$env]) ? $env : self::INFO;
        }
        return self::$minLevel;
    }

    /** افزودن اطلاعات استثنا به context */
    private static function withThrowable(array $context, ?\Throwable $e): array
    {
        if ($e === null) {
            return $context;
        }
        $root = dirname(__DIR__, 2) . '/';
        $context['exception'] = [
            'class' => get_class($e),
            'message' => self::truncate($e->getMessage(), 1000),
            'file' => str_replace($root, '', $e->getFile()) . ':' . $e->getLine(),
        ];
        $trace = [];
        foreach (array_slice($e->getTrace(), 0, 8) as $frame) {
            $trace[] = str_replace($root, '', (string) ($frame['file'] ?? '[internal]'))
                . ':' . (string) ($frame['line'] ?? '0')
                . ' ' . (string) ($frame['function'] ?? '');
        }
        $context['exception']['trace'] = $trace;
        if ($e->getPrevious() !== null) {
            $context['exception']['previous'] = get_class($e->getPrevious()) . ': '
                . self::truncate($e->getPrevious()->getMessage(), 300);
        }
        return $context;
    }

    /** حذف مقادیر حساس و کوتاه‌کردن مقادیر بلند */
    private static function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '[deep]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && in_array(strtolower($k), self::SENSITIVE_KEYS, true)) {
                    $out[$k] = '[redacted]';
                    continue;
                }
                $out[$k] = self::sanitize($v, $depth + 1);
            }
            return $out;
        }
        if ($value instanceof \Throwable) {
            return get_class($value) . ': ' . self::truncate($value->getMessage(), 300);
        }
        if (is_object($value)) {
            return method_exists($value, '__toString')
                ? self::truncate((string) $value, 300)
                : ('[' . get_class($value) . ']');
        }
        if (is_string($value)) {
            return self::truncate($value, 1000);
        }
        if (is_resource($value)) {
            return '[resource]';
        }
        return $value;
    }

    private static function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? (mb_substr($s, 0, $max) . '…') : $s;
    }

    /** اطلاعات مختصر درخواست جاری برای ردیابی */
    private static function requestInfo(): array
    {
        if (PHP_SAPI === 'cli') {
            return ['sapi' => 'cli', 'script' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'cli'))];
        }
        return [
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? '-'),
            'uri' => self::truncate((string) ($_SERVER['REQUEST_URI'] ?? '-'), 300),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
            'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        ];
    }

    // ------------------------------------------------------------ تست/بازبینی

    /** حالت تست: فقط در حافظه نگه‌دار */
    public static function useMemory(bool $on = true): void
    {
        self::$memoryOnly = $on;
    }

    /** @return list<array<string,mixed>> */
    public static function records(?string $level = null): array
    {
        if ($level === null) {
            return self::$buffer;
        }
        return array_values(array_filter(self::$buffer, static fn($r) => $r['level'] === $level));
    }

    public static function reset(): void
    {
        self::$buffer = [];
    }

    /**
     * نصب گیرنده‌های سراسری تا هیچ خطایی بی‌صدا از دست نرود.
     * یک‌بار در ابتدای اجرای برنامه صدا زده می‌شود.
     */
    public static function install(): void
    {
        static $installed = false;
        if ($installed) {
            return;
        }
        $installed = true;

        set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
            // در PHP 8 عملگر @ سطح گزارش را به این ماسک ثابت کاهش می‌دهد.
            // فقط همین حالت را نادیده می‌گیریم؛ در محیط تولید که error_reporting(0)
            // است همچنان باید لاگ بگیریم.
            $suppressed = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;
            if (error_reporting() === $suppressed) {
                return false;
            }
            $map = [
                E_WARNING => self::WARNING, E_USER_WARNING => self::WARNING,
                E_NOTICE => self::INFO, E_USER_NOTICE => self::INFO,
                E_DEPRECATED => self::DEBUG, E_USER_DEPRECATED => self::DEBUG,
            ];
            $root = dirname(__DIR__, 2) . '/';
            self::log($map[$no] ?? self::ERROR, 'php', $str, [
                'at' => str_replace($root, '', $file) . ':' . $line,
            ]);
            return false; // مدیریت پیش‌فرض PHP هم انجام شود
        });

        set_exception_handler(static function (\Throwable $e): void {
            self::critical('uncaught', $e->getMessage(), self::withThrowable([], $e));
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            $root = dirname(__DIR__, 2) . '/';
            self::log(self::CRITICAL, 'php', $err['message'], [
                'at' => str_replace($root, '', (string) $err['file']) . ':' . (int) $err['line'],
            ]);
        });
    }
}
