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
    public string $target_audience = 'all'; // all | owners | tenants | residents | specific_units
    public string $division_method = 'fixed_share';
    public ?array $division_details = null;
    /** شناسه واحدهای هدف وقتی مخاطب «واحدهای خاص» است */
    public ?array $target_unit_ids = null;
    public ?string $due_date = null;
    public string $status = 'pending';
    public bool $is_recurring = false;
    public ?string $recurring_interval = null;
    /** تاریخ شروع/پایان/نوبت بعدی برای قالب‌های دوره‌ای */
    public ?string $recurring_start_date = null;
    public ?string $recurring_end_date = null;
    public ?string $recurring_next_date = null;
    /** نمونه‌های صادرشده به قالب دوره‌ای خود وصل می‌شوند */
    public ?int $parent_cost_id = null;
    public int $created_by;
    public ?string $created_at = null;
    /** زمان صدور هزینه برای مخاطبان (ایجاد ردیف‌های پرداخت و اعلان) */
    public ?string $issued_at = null;

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
            'target_unit_ids' => $this->target_unit_ids,
            'due_date' => $this->due_date,
            'status' => $this->status,
            'is_recurring' => $this->is_recurring,
            'recurring_interval' => $this->recurring_interval,
            'recurring_start_date' => $this->recurring_start_date,
            'recurring_end_date' => $this->recurring_end_date,
            'recurring_next_date' => $this->recurring_next_date,
            'parent_cost_id' => $this->parent_cost_id,
            'issued_at' => $this->issued_at,
        ];
    }
}
