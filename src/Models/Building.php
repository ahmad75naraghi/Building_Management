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
    public ?int $total_units = null;
    public ?int $total_floors = null;
    public bool $has_blocks = true;
    public ?string $default_image = 'b1';
    public int $parking_spots = 0;
    public float $monthly_charge = 0.0;
    public bool $monthly_charge_enabled = false;
    /** نقش کاربر جاری در این ساختمان (manager و ...) — فقط نمایشی */
    public ?string $my_role = null;
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
            'custom_logo_path' => $this->custom_logo_path,
            'theme_color' => $this->theme_color,
            'hierarchy_settings' => $this->hierarchy_settings,
            'total_units' => $this->total_units,
            'total_floors' => $this->total_floors,
            'has_blocks' => $this->has_blocks,
            'default_image' => $this->default_image,
            'parking_spots' => $this->parking_spots,
            'monthly_charge' => $this->monthly_charge,
            'monthly_charge_enabled' => $this->monthly_charge_enabled,
            'my_role' => $this->my_role,
        ];
    }
}
