<?php

declare(strict_types=1);

namespace App\Models;

final class Invitation
{
    public ?int $id = null;
    public int $building_id;
    public ?string $invited_email = null;
    public ?string $invited_phone = null;
    public string $role = 'resident';
    public string $token;
    public string $status = 'pending';
    public int $invited_by;
    public ?string $expires_at = null;
    public ?string $accepted_at = null;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'role' => $this->role,
            'status' => $this->status,
            'token' => $this->token,
            'expires_at' => $this->expires_at,
        ];
    }
}
