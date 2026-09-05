<?php

declare(strict_types=1);

namespace App\Models;

/**
 * گزینه‌های هر رأی‌گیری (نظرسنجی).
 *
 * هر رأی‌گیری می‌تواند چند گزینه داشته باشد و ساکنین به یکی از آن‌ها رأی می‌دهند.
 * جدول مربوطه: vote_options
 */
final class VoteOption
{
    public ?int $id = null;
    public int $vote_id;
    public string $option_text;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vote_id' => $this->vote_id,
            'option_text' => $this->option_text,
        ];
    }
}
