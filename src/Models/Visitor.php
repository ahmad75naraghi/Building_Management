<?php

declare(strict_types=1);

namespace App\Models;

final class Visitor
{
    public ?int $id = null;
    public int $building_id;
    public int $user_id;
    public string $visitor_name;
    public ?string $visitor_car_plate = null;
    public ?string $visit_date = null;
    public ?string $entry_time = null;
    public ?string $exit_time = null;
    public string $status = 'entered'; // entered | exited
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'user_id' => $this->user_id,
            'visitor_name' => $this->visitor_name,
            'visitor_car_plate' => $this->visitor_car_plate,
            'visit_date' => $this->visit_date,
            'entry_time' => $this->entry_time,
            'exit_time' => $this->exit_time,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
