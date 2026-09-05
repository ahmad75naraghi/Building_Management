<?php

declare(strict_types=1);

namespace App\Models;

/**
 * هشدار اضطراری (پنیک/آژیر) ساختمان.
 *
 * جدول: emergency_alerts
 */
final class EmergencyAlert
{
    public ?int $id = null;
    public int $building_id;
    public string $alert_type = 'general'; // general | fire | security | medical | water | gas | elevator
    public string $message;
    public int $sent_by;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'alert_type' => $this->alert_type,
            'message' => $this->message,
            'sent_by' => $this->sent_by,
            'created_at' => $this->created_at,
        ];
    }
}
