<?php

declare(strict_types=1);

namespace App\Models;

final class Penalty
{
    public ?int $id = null;
    public int $cost_payment_id;
    public int $building_id;
    public float $penalty_amount;
    public ?string $applied_at = null;
    public ?string $reason = null;
    public ?int $created_by = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cost_payment_id' => $this->cost_payment_id,
            'building_id' => $this->building_id,
            'penalty_amount' => $this->penalty_amount,
            'reason' => $this->reason,
            'applied_at' => $this->applied_at,
        ];
    }
}
