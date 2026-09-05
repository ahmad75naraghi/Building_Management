<?php

declare(strict_types=1);

namespace App\Models;

final class EmergencyContact
{
    public ?int $id = null;
    public int $building_id;
    public string $contact_name;
    public ?string $contact_role = null;
    public string $phone;
    public ?string $email = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'contact_name' => $this->contact_name,
            'contact_role' => $this->contact_role,
            'phone' => $this->phone,
            'email' => $this->email,
        ];
    }
}
