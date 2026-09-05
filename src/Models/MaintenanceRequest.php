<?php

declare(strict_types=1);

namespace App\Models;

final class MaintenanceRequest
{
    public ?int $id = null;
    public int $building_id;
    public int $user_id;
    public string $title;
    public ?string $description = null;
    public string $status = 'pending'; // pending | in_progress | resolved | closed | rejected
    public ?int $assigned_technician_id = null;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'assigned_technician_id' => $this->assigned_technician_id,
            'created_at' => $this->created_at,
        ];
    }
}
