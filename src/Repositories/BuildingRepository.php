<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Building;
use PDO;

final class BuildingRepository
{
    public function create(Building $building): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO buildings (name, address, created_by, custom_name, theme_color, hierarchy_settings)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $building->name,
            $building->address,
            $building->created_by,
            $building->custom_name,
            $building->theme_color,
            $building->hierarchy_settings ? json_encode($building->hierarchy_settings) : null,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findById(int $id): ?Building
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM buildings WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $this->mapRowToModel($row);
    }

    public function findByUserId(int $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT b.* FROM buildings b
            INNER JOIN building_members bm ON b.id = bm.building_id
            WHERE bm.user_id = ? AND b.deleted_at IS NULL
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => $this->mapRowToModel($r), $rows);
    }

    private function mapRowToModel(array $row): Building
    {
        $building = new Building();
        $building->id = (int) $row['id'];
        $building->name = $row['name'];
        $building->address = $row['address'];
        $building->created_by = (int) $row['created_by'];
        $building->custom_name = $row['custom_name'];
        $building->custom_logo_path = $row['custom_logo_path'];
        $building->theme_color = $row['theme_color'];
        $building->hierarchy_settings = $row['hierarchy_settings'] ? json_decode($row['hierarchy_settings'], true) : null;
        $building->created_at = $row['created_at'];
        return $building;
    }

    public function update(Building $building): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE buildings
            SET name = ?, address = ?, custom_name = ?, theme_color = ?, hierarchy_settings = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmt->execute([
            $building->name,
            $building->address,
            $building->custom_name,
            $building->theme_color,
            $building->hierarchy_settings ? json_encode($building->hierarchy_settings) : null,
            $building->id,
        ]);
    }

    public function delete(int $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE buildings SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
