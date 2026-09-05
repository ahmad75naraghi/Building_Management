<?php

declare(strict_types=1);

namespace App\Models;

final class ConsumptionReading
{
    public ?int $id = null;
    public int $building_id;
    public ?int $unit_id = null;
    public string $consumption_type = 'electricity'; // electricity | water | gas
    public float $reading_value;
    public string $reading_date;
    public ?string $notes = null;
    public int $created_by;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'unit_id' => $this->unit_id,
            'consumption_type' => $this->consumption_type,
            'reading_value' => $this->reading_value,
            'reading_date' => $this->reading_date,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
        ];
    }
}
