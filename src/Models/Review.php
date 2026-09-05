<?php

declare(strict_types=1);

namespace App\Models;

final class Review
{
    public ?int $id = null;
    public int $building_id;
    public int $user_id;
    public ?int $category_id = null;
    public int $rating = 5;
    public ?string $review_text = null;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'user_id' => $this->user_id,
            'category_id' => $this->category_id,
            'rating' => $this->rating,
            'review_text' => $this->review_text,
            'created_at' => $this->created_at,
        ];
    }
}
