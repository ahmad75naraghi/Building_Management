<?php

declare(strict_types=1);

namespace App\Models;

final class Document
{
    public ?int $id = null;
    public int $building_id;
    public string $title;
    public string $file_path;
    public string $document_type = 'general';
    public int $uploaded_by;
    public ?string $created_at = null;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'title' => $this->title,
            'file_path' => $this->file_path,
            'document_type' => $this->document_type,
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at,
        ];
    }
}
