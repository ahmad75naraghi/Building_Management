<?php

declare(strict_types=1);

namespace App\Models;

/** پیام خصوصی بین دو عضو یک ساختمان */
final class Message
{
    public ?int $id = null;
    public int $building_id;
    public int $sender_id;
    public int $recipient_id;
    public string $body;
    public bool $is_read = false;
    public ?string $read_at = null;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'sender_id' => $this->sender_id,
            'recipient_id' => $this->recipient_id,
            'body' => $this->body,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
