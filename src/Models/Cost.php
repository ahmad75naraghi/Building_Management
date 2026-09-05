<?php

declare(strict_types=1);

namespace App\Models;

final class Cost
{
    public ?int $id = null;
    public int $building_id;
    public string $title;
    public ?string $description = null;
    public float $amount;
    public string $cost_type = 'periodic'; // periodic | one_time
    public string $target_audience = 'all'; // all | owners | tenants | residents
    public string $division_method = 'fixed_share';
    public ?array $division_details = null;
    public ?string $due_date = null;
    public string $status = 'pending';
    public bool $is_recurring = false;
    public ?string $recurring_interval = null;
    public int $created_by;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'title' => $this->title,
            'description' => $this->description,
            'amount' => $this->amount,
            'cost_type' => $this->cost_type,
            'target_audience' => $this->target_audience,
            'division_method' => $this->division_method,
            'division_details' => $this->division_details,
            'due_date' => $this->due_date,
            'status' => $this->status,
            'is_recurring' => $this->is_recurring,
            'recurring_interval' => $this->recurring_interval,
        ];
    }
}
