<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Config\AppConfig;

final class FileStorage
{
    /** انواع مجاز برای اسناد ساختمان (تصاویر، پی‌دی‌اف و فایل‌های اداری رایج) */
    public const ALLOWED_DOCUMENT_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** سقف حجم فایل سند: ۱۰ مگابایت */
    public const MAX_DOCUMENT_BYTES = 10485760;

    /**
     * ذخیرهٔ فیش واریزی در پوشهٔ اختصاصی ساختمان و واحد:
     *   storage/buildings/{buildingId}/receipts/unit-{شماره واحد}/...
     * واحدِ بدون شماره در پوشهٔ «بدون-واحد» قرار می‌گیرد تا هیچ رسیدی
     * خارج از ساختار ساختمان/واحد ذخیره نشود.
     */
    public static function saveReceipt(string $content, int $buildingId, string $unitSlug, int $paymentId, string $originalName): ?string
    {
        $safeSlug = trim((string) preg_replace('/[^a-zA-Z0-9\-]/', '-', $unitSlug), '-');
        $unitFolder = $safeSlug !== '' ? 'unit-' . $safeSlug : 'بدون-واحد';
        $dir = AppConfig::getStoragePath('buildings', $buildingId) . '/receipts/' . $unitFolder;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        // استفاده از finfo_buffer به جای finfo_file برای محتوای باینری
        $mimeType = finfo_buffer($fileInfo, $content);
        finfo_close($fileInfo);

        if (!in_array($mimeType, AppConfig::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid file type: ' . $mimeType);
        }

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        $filename = time() . '_' . uniqid() . '.' . $extension;
        $path = $dir . '/' . $filename;

        if (file_put_contents($path, $content) === false) {
            return null;
        }

        return $path;
    }

    /**
     * ذخیرهٔ فایل سند ساختمان با «نام تصادفی غیرقابل حدس».
     *
     * فایل‌ها خارج از دسترس مستقیم وب قرار می‌گیرند (پوشهٔ محافظت‌شده با .htaccess)
     * و فقط از مسیر معتبرسازی‌شدهٔ document_download.php قابل دریافت هستند؛
     * بنابراین کسی نمی‌تواند با دانستن/حدس‌زدن نام فایل، آن را از بیرون باز کند.
     *
     * @return array{path: string, stored_name: string, mime_type: string, file_size: int}
     */
    public static function saveDocument(string $content, int $buildingId): array
    {
        $size = strlen($content);
        if ($size === 0) {
            throw new \InvalidArgumentException('فایل ارسالی خالی است.');
        }
        if ($size > self::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('حجم فایل بیش از حد مجاز (۱۰ مگابایت) است.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo !== false ? (string) finfo_buffer($finfo, $content) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        if (!in_array($mimeType, self::ALLOWED_DOCUMENT_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('نوع فایل مجاز نیست: ' . $mimeType);
        }

        $extension = match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => 'bin',
        };

        $dir = AppConfig::STORAGE_PATH . '/documents/' . $buildingId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        self::protectDocumentsDirectory();

        // نام تصادفی ۳۲ کاراکتری — بدون زمان، بدون هیچ بخش قابل حدسی
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $path = $dir . '/' . $storedName;

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('ذخیره فایل سند ناموفق بود.');
        }

        return [
            'path' => $path,
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'file_size' => $size,
        ];
    }

    /** مسیر مطلق فایل سند روی دیسک */
    public static function documentPath(int $buildingId, string $storedName): string
    {
        // فقط نام ساده پذیرفته می‌شود تا هیچ‌وقت امکان پیمایش مسیر نباشد
        if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $storedName) !== 1) {
            throw new \InvalidArgumentException('نام فایل سند معتبر نیست.');
        }
        return AppConfig::STORAGE_PATH . '/documents/' . $buildingId . '/' . $storedName;
    }

    /**
     * محافظت پوشهٔ اسناد در برابر دسترسی مستقیم وب (برای میزبانی‌های Apache).
     * فایل‌ها فقط باید از مسیر اسکریپت دانلود معتبرسازی‌شده خوانده شوند.
     */
    private static function protectDocumentsDirectory(): void
    {
        $base = AppConfig::STORAGE_PATH . '/documents';
        if (!is_dir($base)) {
            return;
        }
        $htaccess = $base . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
    }

    public static function deleteFile(string $path): bool
    {
        return file_exists($path) ? unlink($path) : true;
    }

    public static function getPublicUrl(string $path): string
    {
        $relative = str_replace(AppConfig::STORAGE_PATH . '/', '', $path);
        return "/storage/{$relative}";
    }
}
