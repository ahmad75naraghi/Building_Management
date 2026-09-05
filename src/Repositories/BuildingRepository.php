<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Building;
use PDO;

final class BuildingRepository
{
    /** @var array<string,bool> */
    private static array $columnsCache = [];

    /**
     * آیا ستون در جدول buildings وجود دارد؟ (سازگاری با دیتابیس قدیمی بدون مایگریت جدید)
     */
    private function hasColumn(string $column): bool
    {
        if (array_key_exists($column, self::$columnsCache)) {
            return self::$columnsCache[$column];
        }
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SHOW COLUMNS FROM buildings LIKE ?");
            $stmt->execute([$column]);
            self::$columnsCache[$column] = (bool) $stmt->fetch();
        } catch (\Throwable $e) {
            self::$columnsCache[$column] = false;
        }
        return self::$columnsCache[$column];
    }

    public function create(Building $building): ?int
    {
        $db = Database::getConnection();
        $cols = ['name', 'address', 'created_by', 'custom_name', 'theme_color', 'hierarchy_settings'];
        $vals = [
            $building->name,
            $building->address,
            $building->created_by,
            $building->custom_name,
            $building->theme_color,
            $building->hierarchy_settings ? json_encode($building->hierarchy_settings) : null,
        ];
        $extra = [
            'total_units' => $building->total_units,
            'total_floors' => $building->total_floors,
            'has_blocks' => (int) $building->has_blocks,
            'default_image' => $building->default_image,
            'parking_spots' => $building->parking_spots,
            'monthly_charge' => $building->monthly_charge,
            'monthly_charge_enabled' => (int) $building->monthly_charge_enabled,
        ];
        foreach ($extra as $col => $val) {
            if ($this->hasColumn($col)) {
                $cols[] = $col;
                $vals[] = $val;
            }
        }
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $db->prepare("INSERT INTO buildings (" . implode(', ', $cols) . ") VALUES ({$placeholders})");
        $stmt->execute($vals);
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
            SELECT b.*, bm.role AS member_role FROM buildings b
            INNER JOIN building_members bm ON b.id = bm.building_id
            WHERE bm.user_id = ? AND b.deleted_at IS NULL
            ORDER BY b.id DESC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => $this->mapRowToModel($r), $rows);
    }

    /**
     * نقش یک کاربر در یک ساختمان (manager و ...) یا null.
     */
    public function findMemberRole(int $userId, int $buildingId): ?string
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT role FROM building_members WHERE user_id = ? AND building_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$userId, $buildingId]);
        $role = $stmt->fetchColumn();
        return $role !== false ? (string) $role : null;
    }

    private function mapRowToModel(array $row): Building
    {
        $building = new Building();
        $building->id = (int) $row['id'];
        $building->name = $row['name'];
        $building->address = $row['address'];
        $building->created_by = (int) $row['created_by'];
        $building->custom_name = $row['custom_name'];
        $building->custom_logo_path = $row['custom_logo_path'] ?? null;
        $building->theme_color = $row['theme_color'];
        $building->hierarchy_settings = !empty($row['hierarchy_settings']) ? json_decode($row['hierarchy_settings'], true) : null;
        $building->total_units = isset($row['total_units']) && $row['total_units'] !== null ? (int) $row['total_units'] : null;
        $building->total_floors = isset($row['total_floors']) && $row['total_floors'] !== null ? (int) $row['total_floors'] : null;
        $building->has_blocks = isset($row['has_blocks']) ? (bool) $row['has_blocks'] : true;
        $building->default_image = $row['default_image'] ?? 'b1';
        $building->parking_spots = isset($row['parking_spots']) ? (int) $row['parking_spots'] : 0;
        $building->monthly_charge = isset($row['monthly_charge']) ? (float) $row['monthly_charge'] : 0.0;
        $building->monthly_charge_enabled = isset($row['monthly_charge_enabled']) ? (bool) $row['monthly_charge_enabled'] : false;
        $building->my_role = $row['member_role'] ?? null;
        $building->created_at = $row['created_at'];
        return $building;
    }

    public function update(Building $building): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE buildings
            SET name = ?, address = ?, custom_name = ?, theme_color = ?, hierarchy_settings = ?,
                total_units = ?, total_floors = ?, has_blocks = ?, default_image = ?,
                parking_spots = ?, monthly_charge = ?, monthly_charge_enabled = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmt->execute([
            $building->name,
            $building->address,
            $building->custom_name,
            $building->theme_color,
            $building->hierarchy_settings ? json_encode($building->hierarchy_settings) : null,
            $building->total_units,
            $building->total_floors,
            (int) $building->has_blocks,
            $building->default_image,
            $building->parking_spots,
            $building->monthly_charge,
            (int) $building->monthly_charge_enabled,
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
