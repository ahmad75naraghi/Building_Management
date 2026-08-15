<?php

declare(strict_types=1);

namespace App\Models;

final class TicketComment
{
    public ?int $id = null;
    public int $ticket_id;
    public int $user_id;
    public string $comment;
    public bool $is_internal = false;
    public ?string $attachment_path = null;
    public ?string $created_at = null;
}
