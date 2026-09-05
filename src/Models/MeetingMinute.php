<?php

declare(strict_types=1);

namespace App\Models;

/**
 * صورت‌جلسه.
 *
 * جدول: meeting_minutes
 */
final class MeetingMinute
{
    public ?int $id = null;
    public int $meeting_id;
    public string $minutes_content;
    public int $recorded_by;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'meeting_id' => $this->meeting_id,
            'minutes_content' => $this->minutes_content,
            'recorded_by' => $this->recorded_by,
            'created_at' => $this->created_at,
        ];
    }
}
