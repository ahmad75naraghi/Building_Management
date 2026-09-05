<?php

declare(strict_types=1);

namespace App\Models;

final class Receipt
{
    public ?int $id = null;
    public int $cost_payment_id;
    public string $file_path;
    public ?int $file_size = null;
    public ?string $mime_type = null;
    public ?string $original_name = null;
    public bool $is_public = false;
    public ?string $created_at = null;
}
