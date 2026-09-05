<?php

declare(strict_types=1);

namespace App\Models;

final class Booking
{
    public ?int $id = null;
    public int $building_id;
    public ?int $common_area_id = null;
    public int $user_id;
    public string $booking_date;
    public ?string $start_time = null;
    public ?string $end_time = null;
    public string $status = 'pending'; // pending | confirmed | cancelled
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'common_area_id' => $this->common_area_id,
            'user_id' => $this->user_id,
            'booking_date' => $this->booking_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
