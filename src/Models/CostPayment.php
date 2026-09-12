<?php

declare(strict_types=1);

namespace App\Models;

final class CostPayment
{
    public ?int $id = null;
    public int $cost_id;
    public int $user_id;
    /** واحدی که این ردیف پرداخت به آن منتسب است (برای ماندهٔ بدهکار/طلبکار واحد) */
    public ?int $unit_id = null;
    public ?float $amount_paid = null;
    /** سهم درخواست‌شده از این پرداخت‌کننده هنگام صدور هزینه */
    public ?float $share_amount = null;
    public string $status = 'pending';
    public ?string $receipt_path = null;
    public bool $receipt_is_public = false;
    public ?string $notes = null;
    public ?string $reject_reason = null;
    public ?int $confirmed_by = null;
    public ?string $confirmed_at = null;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cost_id' => $this->cost_id,
            'user_id' => $this->user_id,
            'unit_id' => $this->unit_id,
            'amount_paid' => $this->amount_paid,
            'share_amount' => $this->share_amount,
            'status' => $this->status,
            'receipt_is_public' => $this->receipt_is_public,
            'notes' => $this->notes,
            'reject_reason' => $this->reject_reason,
            'confirmed_by' => $this->confirmed_by,
            'confirmed_at' => $this->confirmed_at,
        ];
    }
}
