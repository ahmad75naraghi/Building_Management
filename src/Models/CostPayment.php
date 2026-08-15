<?php

declare(strict_types=1);

namespace App\Models;

final class CostPayment
{
    public ?int $id = null;
    public int $cost_id;
    public int $user_id;
    public ?float $amount_paid = null;
    public string $status = 'pending';
    public ?string $receipt_path = null;
    public bool $receipt_is_public = false;
    public ?string $notes = null;
    public ?int $confirmed_by = null;
    public ?string $confirmed_at = null;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cost_id' => $this->cost_id,
            'user_id' => $this->user_id,
            'amount_paid' => $this->amount_paid,
            'status' => $this->status,
            'receipt_is_public' => $this->receipt_is_public,
            'notes' => $this->notes,
            'confirmed_by' => $this->confirmed_by,
            'confirmed_at' => $this->confirmed_at,
        ];
    }
}
