<?php

declare(strict_types=1);

namespace App\Models;

final class Ticket
{
    public ?int $id = null;
    public int $building_id;
    public int $user_id;
    public ?int $unit_id = null;
    public string $category = 'technical';
    public bool $is_anonymous = false;
    public string $title;
    public string $description;
    public string $status = 'open';
    public string $priority = 'normal';
    public ?int $assigned_to = null;
    public ?string $resolved_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'category' => $this->category,
            'is_anonymous' => $this->is_anonymous,
            'title' => $this->title,
            'status' => $this->status,
            'priority' => $this->priority,
            'assigned_to' => $this->assigned_to,
            'created_at' => $this->created_at,
        ];
    }
}
