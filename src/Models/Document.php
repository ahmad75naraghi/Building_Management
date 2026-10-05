<?php

declare(strict_types=1);

namespace App\Models;

final class Document
{
    /** دسته‌بندی‌های رسمی اسناد ساختمان (کلید => برچسب فارسی) */
    public const CATEGORIES = [
        'legal' => 'اسناد مالکیت و حقوقی',
        'financial' => 'مالی و شارژ',
        'meeting' => 'صورت‌جلسات',
        'contract' => 'قراردادها',
        'insurance' => 'بیمه',
        'technical' => 'تأسیسات و فنی',
        'rules' => 'اساسنامه و قوانین',
        'other' => 'سایر',
    ];

    public ?int $id = null;
    public int $building_id;
    public string $title;
    public string $file_path;
    public string $document_type = 'other';
    public int $uploaded_by;
    public ?string $created_at = null;
    /** نام تصادفی فایل روی دیسک — اگر نال باشد سند «لینک خارجی» است */
    public ?string $stored_name = null;
    public ?string $mime_type = null;
    public ?int $file_size = null;
    public int $is_visible_to_members = 1;
    public ?string $updated_at = null;

    /** آیا این سند فایل آپلودشده است (در مقابل لینک خارجی)؟ */
    public function isUploadedFile(): bool
    {
        return $this->stored_name !== null && $this->stored_name !== '';
    }

    /**
     * نرمال‌سازی دستهٔ سند به یکی از کلیدهای رسمی.
     * مقدارهای ناشناخته یا قدیمی (مثل 'general') به «سایر» می‌روند.
     */
    public static function normalizeCategory(?string $type): string
    {
        $type = trim((string) $type);
        if ($type === '' || $type === 'general') {
            return 'other';
        }
        return array_key_exists($type, self::CATEGORIES) ? $type : 'other';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'title' => $this->title,
            'file_path' => $this->file_path,
            'document_type' => $this->document_type,
            'category_label' => self::CATEGORIES[$this->document_type] ?? 'سایر',
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at,
            'stored_name' => $this->stored_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'is_uploaded' => $this->isUploadedFile(),
            'is_visible_to_members' => $this->is_visible_to_members,
            'updated_at' => $this->updated_at,
        ];
    }
}
