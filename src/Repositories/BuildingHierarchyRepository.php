<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\BuildingHierarchy;
use PDO;

final class BuildingHierarchyRepository
{
    public function findOrCreateByBuildingId(int $buildingId): BuildingHierarchy
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM building_hierarchy_settings WHERE building_id = ?");
        $stmt->execute([$buildingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return $this->mapRow($row);
        }

        $hierarchy = new BuildingHierarchy();
        $hierarchy->building_id = $buildingId;
        $hierarchy->has_blocks = true;
        $hierarchy->has_floors = true;
        $hierarchy->has_units = true;
        $hierarchy->has_common_areas = true;
        $hierarchy->settings_json = [
            'enable_blocks' => true,
            'enable_floors' => true,
            'enable_units' => true,
            'enable_common_areas' => true,
        ];

        $stmt = $db->prepare("
            INSERT INTO building_hierarchy_settings (building_id, has_blocks, has_floors, has_units, has_common_areas, settings_json)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $hierarchy->building_id,
            (int) $hierarchy->has_blocks,
            (int) $hierarchy->has_floors,
            (int) $hierarchy->has_units,
            (int) $hierarchy->has_common_areas,
            json_encode($hierarchy->settings_json),
        ]);
        $hierarchy->id = (int) $db->lastInsertId();
        return $hierarchy;
    }

    public function updateByBuildingId(int $buildingId, array $settings): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE building_hierarchy_settings
            SET has_blocks = ?, has_floors = ?, has_units = ?, has_common_areas = ?, settings_json = ?, updated_at = CURRENT_TIMESTAMP
            WHERE building_id = ?
        ");
        return $stmt->execute([
            (int) ($settings['has_blocks'] ?? true),
            (int) ($settings['has_floors'] ?? true),
            (int) ($settings['has_units'] ?? true),
            (int) ($settings['has_common_areas'] ?? true),
            json_encode($settings),
            $buildingId,
        ]);
    }

    private function mapRow(array $row): BuildingHierarchy
    {
        $h = new BuildingHierarchy();
        $h->id = (int) $row['id'];
        $h->building_id = (int) $row['building_id'];
        $h->has_blocks = (bool) $row['has_blocks'];
        $h->has_floors = (bool) $row['has_floors'];
        $h->has_units = (bool) $row['has_units'];
        $h->has_common_areas = (bool) $row['has_common_areas'];
        $h->settings_json = $row['settings_json'] ? json_decode($row['settings_json'], true) : null;
        return $h;
    }
}
