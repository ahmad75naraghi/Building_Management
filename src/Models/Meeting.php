<?php

declare(strict_types=1);

namespace App\Models;

final class Meeting
{
    public ?int $id = null;
    public int $building_id;
    public string $title;
    public ?string $description = null;
    public string $meeting_date;
    public ?string $location = null;
    public string $status = 'scheduled'; // scheduled | held | cancelled
    public int $created_by;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'title' => $this->title,
            'description' => $this->description,
            'meeting_date' => $this->meeting_date,
            'location' => $this->location,
            'status' => $this->status,
            'created_by' => $this->created_by,
        ];
    }
}
