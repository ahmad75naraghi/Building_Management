<?php

declare(strict_types=1);

namespace App\Models;

final class Vote
{
    public ?int $id = null;
    public int $building_id;
    public string $title;
    public ?string $description = null;
    public ?string $start_date = null;
    public ?string $end_date = null;
    public string $status = 'active'; // active | closed
    public int $created_by;

    // فیلدهای کمکی (پُرشده هنگام لیست/جزئیات — در جدول ذخیره نمی‌شوند)
    /** @var array<int, array{id:int, vote_id:int, option_text:string, votes_count:int}> */
    public array $options = [];

    /** @var array{total_votes:int, options:array<int, array{option_id:int, option_text:string, votes_count:int, percentage:float}>} */
    public array $results = [];

    public bool $user_has_voted = false;
    public ?int $my_option_id = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'title' => $this->title,
            'description' => $this->description,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'options' => $this->options,
            'results' => $this->results,
            'user_has_voted' => $this->user_has_voted,
            'my_option_id' => $this->my_option_id,
        ];
    }
}
