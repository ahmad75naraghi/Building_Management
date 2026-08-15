<?php

declare(strict_types=1);

namespace App\Models;

final class Unit
{
    public ?int $id = null;
    public int $building_id;
    public ?int $block_id = null;
    public ?int $floor_id = null;
    public ?string $unit_number = null;
    public ?float $area = null;
    public ?string $type = 'residential'; // residential | commercial
    public ?int $owner_user_id = null;
    public ?int $tenant_user_id = null;
    public ?string $created_at = null;
}
