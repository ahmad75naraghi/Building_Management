<?php

declare(strict_types=1);

namespace App\Models;

final class PenaltySetting
{
    public ?int $id = null;
    public int $building_id;
    public string $penalty_type = 'percentage'; // percentage | fixed_amount
    public float $penalty_value;
    public int $delay_days = 1;
    public string $applies_to = 'unconfirmed_payments';
    public bool $is_active = true;
    public int $created_by;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'penalty_type' => $this->penalty_type,
            'penalty_value' => $this->penalty_value,
            'delay_days' => $this->delay_days,
            'applies_to' => $this->applies_to,
            'is_active' => $this->is_active,
        ];
    }
}
