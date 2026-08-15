<?php

declare(strict_types=1);

namespace App\Models;

final class Floor
{
    public ?int $id = null;
    public int $building_id;
    public ?int $block_id = null;
    public ?int $floor_number = null;
    public ?string $name = null;
    public ?string $created_at = null;
}
