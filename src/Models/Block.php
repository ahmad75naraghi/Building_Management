<?php

declare(strict_types=1);

namespace App\Models;

final class Block
{
    public ?int $id = null;
    public int $building_id;
    public ?string $name = null;
    public ?string $description = null;
    public ?string $created_at = null;
}
