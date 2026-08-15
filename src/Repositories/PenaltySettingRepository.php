<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\PenaltySetting;
use PDO;

final class PenaltySettingRepository
{
    public function create(PenaltySetting $setting): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO penalty_settings (building_id, penalty_type, penalty_value, delay_days, applies_to, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $setting->building_id,
            $setting->penalty_type,
            $setting->penalty_value,
            $setting->delay_days,
            $setting->applies_to,
            (int) $setting->is_active,
            $setting->created_by,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findActiveByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM penalty_settings WHERE building_id = ? AND is_active = 1");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapRow(array $row): PenaltySetting
    {
        $s = new PenaltySetting();
        $s->id = (int) $row['id'];
        $s->building_id = (int) $row['building_id'];
        $s->penalty_type = $row['penalty_type'];
        $s->penalty_value = (float) $row['penalty_value'];
        $s->delay_days = (int) $row['delay_days'];
        $s->applies_to = $row['applies_to'];
        $s->is_active = (bool) $row['is_active'];
        $s->created_by = (int) $row['created_by'];
        $s->created_at = $row['created_at'];
        return $s;
    }
}
