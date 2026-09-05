<?php

declare(strict_types=1);

namespace App\Models;

/**
 * دسته‌بندی نظرات (مثلاً: خدمات مدیریت، نظافت، تاسیسات، امنیت).
 *
 * جدول: review_categories
 */
final class ReviewCategory
{
    public ?int $id = null;
    public string $name;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
