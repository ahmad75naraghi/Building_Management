<?php

declare(strict_types=1);

namespace App\Models;

final class Building
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $address = null;
    public ?int $created_by = null;
    public ?string $custom_name = null;
    public ?string $custom_logo_path = null;
    public ?string $theme_color = null;
    public ?array $hierarchy_settings = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
    public ?string $deleted_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'custom_name' => $this->custom_name,
            'theme_color' => $this->theme_color,
            'hierarchy_settings' => $this->hierarchy_settings,
        ];
    }
}
