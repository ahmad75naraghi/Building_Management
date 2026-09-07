<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\Unit;
use App\Utilities\Validator;
use PDO;

/**
 * منطق واحدها: ایجاد، لیست (با مالک/مستاجر/ساکن)، ویرایش و حذف.
 *
 * سه سناریوی اصلی سکونت:
 *  1) مالک ساکن است   → owner_user_id + owner_resident=1 + tenant=null
 *  2) مستاجر ساکن است → owner_user_id + tenant_user_id
 *  3) واحد خالی       → owner_user_id + owner_resident=0 + tenant=null
 */
final class UnitService
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function isBuildingMember(int $userId, int $buildingId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id FROM building_members
             WHERE user_id = ? AND building_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$userId, $buildingId]);
        return (bool) $stmt->fetchColumn();
    }

    private function getUnitBuildingId(int $unitId): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT building_id FROM units WHERE id = ? LIMIT 1");
        $stmt->execute([$unitId]);
        $buildingId = $stmt->fetchColumn();
        return $buildingId !== false ? (int) $buildingId : null;
    }

    /**
     * مقدار متنی اختیاری را تمیز می‌کند: فاصله‌های اضافی حذف و رشته خالی به نال تبدیل می‌شود.
     */
    private function cleanOptionalText($value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    /**
     * اعتبارسنجی مالک/مستاجر: هرکدام اگر داده شود باید عضو فعال همان ساختمان باشد.
     *
     * @return array{owner_user_id: ?int, tenant_user_id: ?int, owner_resident: bool}
     */
    private function validateOccupants(array $data, int $buildingId): array
    {
        $ownerUserId = isset($data['owner_user_id']) && $data['owner_user_id'] !== '' && $data['owner_user_id'] !== null
            ? (int) $data['owner_user_id']
            : null;
        $tenantUserId = isset($data['tenant_user_id']) && $data['tenant_user_id'] !== '' && $data['tenant_user_id'] !== null
            ? (int) $data['tenant_user_id']
            : null;

        if ($ownerUserId !== null && !$this->isBuildingMember($ownerUserId, $buildingId)) {
            throw new ValidationException('مالک انتخاب‌شده عضو فعال این ساختمان نیست.');
        }
        if ($tenantUserId !== null && !$this->isBuildingMember($tenantUserId, $buildingId)) {
            throw new ValidationException('مستاجر انتخاب‌شده عضو فعال این ساختمان نیست.');
        }
        if ($ownerUserId !== null && $ownerUserId === $tenantUserId) {
            throw new ValidationException('مالک و مستاجر نمی‌توانند یک نفر باشند.');
        }

        // اگر مستاجر هست، ساکن مستاجر است؛ مالک نمی‌تواند هم‌زمان ساکن باشد
        $ownerResident = $tenantUserId === null
            ? (bool) ($data['owner_resident'] ?? false)
            : false;

        return [
            'owner_user_id' => $ownerUserId,
            'tenant_user_id' => $tenantUserId,
            'owner_resident' => $ownerResident,
        ];
    }

    /**
     * محاسبه وضعیت سکونت واحد از روی داده‌های آن.
     */
    private function computeOccupancy(Unit $unit): void
    {
        if ($unit->tenant_user_id !== null) {
            $unit->occupant_id = $unit->tenant_user_id;
            $unit->occupant_name = $unit->tenant_name;
            $unit->occupant_type = 'tenant';
            $unit->is_occupied = true;
            $unit->occupancy_status = 'tenant_occupied';
        } elseif ($unit->owner_user_id !== null && $unit->owner_resident) {
            $unit->occupant_id = $unit->owner_user_id;
            $unit->occupant_name = $unit->owner_name;
            $unit->occupant_type = 'owner';
            $unit->is_occupied = true;
            $unit->occupancy_status = 'owner_occupied';
        } elseif ($unit->owner_user_id !== null) {
            $unit->occupant_id = null;
            $unit->occupant_name = null;
            $unit->occupant_type = null;
            $unit->is_occupied = false;
            $unit->occupancy_status = 'vacant'; // مالک دارد ولی ساکن نیست
        } else {
            $unit->occupant_id = null;
            $unit->occupant_name = null;
            $unit->occupant_type = null;
            $unit->is_occupied = false;
            $unit->occupancy_status = 'no_owner';
        }
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    /**
     * @return array<int, Unit>
     */
    public function listUnitsByBuilding(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT u.*,
                    o.name AS owner_name, o.email AS owner_email, o.phone AS owner_phone,
                    t.name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone
             FROM units u
             LEFT JOIN users o ON o.id = u.owner_user_id
             LEFT JOIN users t ON t.id = u.tenant_user_id
             WHERE u.building_id = ?
             ORDER BY u.floor_id ASC, u.unit_number ASC, u.id ASC"
        );
        $stmt->execute([$buildingId]);

        $units = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $unit = $this->mapRow($row);
            $this->computeOccupancy($unit);
            $units[] = $unit;
        }
        return $units;
    }

    public function getUnitById(int $unitId, int $userId): ?Unit
    {
        $buildingId = $this->getUnitBuildingId($unitId);
        if ($buildingId === null || !$this->isBuildingMember($userId, $buildingId)) {
            return null;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT u.*,
                    o.name AS owner_name, o.email AS owner_email, o.phone AS owner_phone,
                    t.name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone
             FROM units u
             LEFT JOIN users o ON o.id = u.owner_user_id
             LEFT JOIN users t ON t.id = u.tenant_user_id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([$unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $unit = $this->mapRow($row);
        $this->computeOccupancy($unit);
        return $unit;
    }

    public function createUnit(array $data, int $buildingId, int $userId): Unit
    {
        if (!$this->isBuildingMember($userId, $buildingId)) {
            throw new AppException('شما عضو این ساختمان نیستید.');
        }

        $errors = Validator::validate($data, ['unit_number' => 'required']);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $occupants = $this->validateOccupants($data, $buildingId);

        $unit = new Unit();
        $unit->building_id = $buildingId;
        $unit->unit_number = trim((string) $data['unit_number']);
        $unit->type = $data['type'] ?? 'residential';
        $unit->area = !empty($data['area']) ? (float) $data['area'] : null;
        $unit->block_id = !empty($data['block_id']) ? (int) $data['block_id'] : null;
        $unit->floor_id = !empty($data['floor_id']) ? (int) $data['floor_id'] : null;
        $unit->owner_user_id = $occupants['owner_user_id'];
        $unit->tenant_user_id = $occupants['tenant_user_id'];
        $unit->owner_resident = $occupants['owner_resident'];
        $unit->residents_count = isset($data['residents_count']) ? max(0, (int) $data['residents_count']) : 0;
        $unit->custom_charge = isset($data['custom_charge']) && $data['custom_charge'] !== '' && $data['custom_charge'] !== null
            ? max(0, (float) $data['custom_charge']) : null;
        $unit->parking_no = $this->cleanOptionalText($data['parking_no'] ?? null);
        $unit->storage_no = $this->cleanOptionalText($data['storage_no'] ?? null);

        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO units (building_id, block_id, floor_id, unit_number, area, type, owner_user_id, tenant_user_id, owner_resident, residents_count, custom_charge, parking_no, storage_no)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $unit->building_id,
            $unit->block_id,
            $unit->floor_id,
            $unit->unit_number,
            $unit->area,
            $unit->type,
            $unit->owner_user_id,
            $unit->tenant_user_id,
            (int) $unit->owner_resident,
            $unit->residents_count,
            $unit->custom_charge,
            $unit->parking_no,
            $unit->storage_no,
        ]);
        $unit->id = (int) $db->lastInsertId();
        $this->computeOccupancy($unit);
        return $unit;
    }

    public function updateUnit(int $unitId, array $data, int $userId): ?Unit
    {
        $buildingId = $this->getUnitBuildingId($unitId);
        if ($buildingId === null) {
            return null;
        }
        if (!$this->isBuildingMember($userId, $buildingId)) {
            throw new AppException('شما عضو این ساختمان نیستید.');
        }

        $errors = Validator::validate($data, ['unit_number' => 'required']);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $occupants = $this->validateOccupants($data, $buildingId);

        $db = Database::getConnection();
        $stmt = $db->prepare(
            "UPDATE units SET
                unit_number = ?, area = ?, type = ?, block_id = ?, floor_id = ?,
                owner_user_id = ?, tenant_user_id = ?, owner_resident = ?,
                residents_count = ?, custom_charge = ?, parking_no = ?, storage_no = ?
             WHERE id = ?"
        );
        $stmt->execute([
            trim((string) ($data['unit_number'] ?? '')),
            !empty($data['area']) ? (float) $data['area'] : null,
            $data['type'] ?? 'residential',
            !empty($data['block_id']) ? (int) $data['block_id'] : null,
            !empty($data['floor_id']) ? (int) $data['floor_id'] : null,
            $occupants['owner_user_id'],
            $occupants['tenant_user_id'],
            (int) $occupants['owner_resident'],
            isset($data['residents_count']) ? max(0, (int) $data['residents_count']) : 0,
            isset($data['custom_charge']) && $data['custom_charge'] !== '' && $data['custom_charge'] !== null
                ? max(0, (float) $data['custom_charge']) : null,
            $this->cleanOptionalText($data['parking_no'] ?? null),
            $this->cleanOptionalText($data['storage_no'] ?? null),
            $unitId,
        ]);

        return $this->getUnitById($unitId, $userId);
    }

    public function deleteUnit(int $unitId, int $userId): bool
    {
        $buildingId = $this->getUnitBuildingId($unitId);
        if ($buildingId === null) {
            return false;
        }
        if (!$this->isBuildingMember($userId, $buildingId)) {
            throw new AppException('شما عضو این ساختمان نیستید.');
        }
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM units WHERE id = ?");
        return $stmt->execute([$unitId]);
    }

    // ------------------------------------------------------------------

    private function mapRow(array $row): Unit
    {
        $u = new Unit();
        $u->id = (int) $row['id'];
        $u->building_id = (int) $row['building_id'];
        $u->block_id = $row['block_id'] !== null ? (int) $row['block_id'] : null;
        $u->floor_id = $row['floor_id'] !== null ? (int) $row['floor_id'] : null;
        $u->unit_number = $row['unit_number'];
        $u->area = $row['area'] !== null ? (float) $row['area'] : null;
        $u->type = $row['type'];
        $u->owner_user_id = $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null;
        $u->tenant_user_id = $row['tenant_user_id'] !== null ? (int) $row['tenant_user_id'] : null;
        $u->owner_resident = (bool) ($row['owner_resident'] ?? 0);
        $u->residents_count = isset($row['residents_count']) ? (int) $row['residents_count'] : 0;
        $u->custom_charge = isset($row['custom_charge']) && $row['custom_charge'] !== null
            ? (float) $row['custom_charge'] : null;
        $u->parking_no = isset($row['parking_no']) && $row['parking_no'] !== null ? (string) $row['parking_no'] : null;
        $u->storage_no = isset($row['storage_no']) && $row['storage_no'] !== null ? (string) $row['storage_no'] : null;
        $u->created_at = $row['created_at'] ?? null;
        $u->owner_name = $row['owner_name'] ?? null;
        $u->owner_email = $row['owner_email'] ?? null;
        $u->owner_phone = $row['owner_phone'] ?? null;
        $u->tenant_name = $row['tenant_name'] ?? null;
        $u->tenant_email = $row['tenant_email'] ?? null;
        $u->tenant_phone = $row['tenant_phone'] ?? null;
        return $u;
    }
}
