<?php

declare(strict_types=1);

namespace App\Models;

/**
 * واحد ساختمان.
 *
 * سناریوهای سکونت (همه در owner_user_id / tenant_user_id / owner_resident):
 *  1) مالک ساکن است:  owner_user_id = X، tenant_user_id = NULL، owner_resident = 1
 *  2) مستاجر ساکن است: owner_user_id = X، tenant_user_id = Y (Y != X)
 *  3) واحد خالی:      owner_user_id = X، tenant_user_id = NULL، owner_resident = 0
 *  4) بدون مالک:      owner_user_id = NULL
 *
 * جدول: units
 */
final class Unit
{
    public ?int $id = null;
    public int $building_id;
    public ?int $block_id = null;
    public ?int $floor_id = null;
    public ?string $unit_number = null;
    public ?float $area = null;
    public ?string $type = 'residential'; // residential | commercial | office | parking | storage
    public ?int $owner_user_id = null;
    public ?int $tenant_user_id = null;
    public bool $owner_resident = false;
    /** تعداد نفرات ساکن واحد (مبنای شارژ نفری) */
    public int $residents_count = 0;
    /** شارژ دلخواه این واحد (حالت custom) */
    public ?float $custom_charge = null;
    public ?string $created_at = null;

    // فیلدهای الحاقی (از JOIN با users — فقط در خروجی، نه در دیتابیس)
    public ?string $owner_name = null;
    public ?string $owner_email = null;
    public ?string $owner_phone = null;
    public ?string $tenant_name = null;
    public ?string $tenant_email = null;
    public ?string $tenant_phone = null;

    // فیلدهای محاسبه‌شده سکونت
    public ?int $occupant_id = null;
    public ?string $occupant_name = null;
    public ?string $occupant_type = null;   // 'owner' | 'tenant' | null
    public bool $is_occupied = false;
    public ?string $occupancy_status = null; // 'owner_occupied' | 'tenant_occupied' | 'vacant' | 'no_owner'

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'block_id' => $this->block_id,
            'floor_id' => $this->floor_id,
            'unit_number' => $this->unit_number,
            'area' => $this->area,
            'type' => $this->type,
            'owner_user_id' => $this->owner_user_id,
            'tenant_user_id' => $this->tenant_user_id,
            'owner_resident' => $this->owner_resident,
            'residents_count' => $this->residents_count,
            'custom_charge' => $this->custom_charge,
            'created_at' => $this->created_at,
            // اطلاعات مالک / مستاجر
            'owner_name' => $this->owner_name,
            'owner_email' => $this->owner_email,
            'owner_phone' => $this->owner_phone,
            'tenant_name' => $this->tenant_name,
            'tenant_email' => $this->tenant_email,
            'tenant_phone' => $this->tenant_phone,
            // وضعیت سکونت
            'occupant_id' => $this->occupant_id,
            'occupant_name' => $this->occupant_name,
            'occupant_type' => $this->occupant_type,
            'is_occupied' => $this->is_occupied,
            'occupancy_status' => $this->occupancy_status,
        ];
    }
}
