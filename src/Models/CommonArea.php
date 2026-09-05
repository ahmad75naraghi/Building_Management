<?php

declare(strict_types=1);

namespace App\Models;

final class CommonArea
{
    public ?int $id = null;
    public int $building_id;
    public ?string $name = null; // پارکینگ، سالن، استخر...
    public ?string $type = null;
    public ?string $description = null;
    public ?bool $bookable = false;
    public ?string $created_at = null;
}
