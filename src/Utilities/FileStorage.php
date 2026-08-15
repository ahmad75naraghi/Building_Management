<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Config\AppConfig;

final class FileStorage
{
    public static function saveReceipt(string $content, int $buildingId, int $paymentId, string $originalName): ?string
    {
        $dir = AppConfig::getStoragePath('buildings', $buildingId) . '/receipts/' . $paymentId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $content);
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
