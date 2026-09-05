<?php

declare(strict_types=1);

namespace App\Models;

final class Announcement
{
    public ?int $id = null;
    public int $building_id;
    public string $title;
    public string $content;
    public bool $is_pinned = false;
    public int $created_by;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'title' => $this->title,
            'content' => $this->content,
            'is_pinned' => $this->is_pinned,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
