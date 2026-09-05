<?php

declare(strict_types=1);

namespace App\Models;

final class Notification
{
    public ?int $id = null;
    public int $user_id;
    public ?int $building_id = null;
    public string $notification_type = 'general';
    public string $title;
    public ?string $message = null;
    public ?array $data = null;
    public bool $is_read = false;
    public bool $is_email_sent = false;
    public ?string $read_at = null;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'building_id' => $this->building_id,
            'notification_type' => $this->notification_type,
            'title' => $this->title,
            'message' => $this->message,
            'is_read' => $this->is_read,
            'created_at' => $this->created_at,
        ];
    }
}
