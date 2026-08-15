<?php

declare(strict_types=1);

namespace App\Models;

final class BuildingHierarchy
{
    public ?int $id = null;
    public int $building_id;
    public bool $has_blocks = false;
    public bool $has_floors = false;
    public bool $has_units = false;
    public bool $has_common_areas = false;
    public ?array $settings_json = null;

    public function toArray(): array
    {
        return [
            'building_id' => $this->building_id,
            'has_blocks' => $this->has_blocks,
            'has_floors' => $this->has_floors,
            'has_units' => $this->has_units,
            'has_common_areas' => $this->has_common_areas,
            'settings_json' => $this->settings_json,
        ];
    }
}
