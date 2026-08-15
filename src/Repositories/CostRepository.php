<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Cost;
use PDO;

final class CostRepository
{
    public function create(Cost $cost): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO costs (building_id, title, description, amount, cost_type, target_audience, division_method, division_details, due_date, status, is_recurring, recurring_interval, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $cost->building_id,
            $cost->title,
            $cost->description,
            $cost->amount,
            $cost->cost_type,
            $cost->target_audience,
            $cost->division_method,
            $cost->division_details ? json_encode($cost->division_details) : null,
            $cost->due_date,
            $cost->status,
            (int) $cost->is_recurring,
            $cost->recurring_interval,
            $cost->created_by,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findById(int $id): ?Cost
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM costs WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRow($row) : null;
    }

    public function findByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM costs WHERE building_id = ? ORDER BY created_at DESC");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapRow(array $row): Cost
    {
        $cost = new Cost();
        $cost->id = (int) $row['id'];
        $cost->building_id = (int) $row['building_id'];
        $cost->title = $row['title'];
        $cost->description = $row['description'];
        $cost->amount = (float) $row['amount'];
        $cost->cost_type = $row['cost_type'];
        $cost->target_audience = $row['target_audience'];
        $cost->division_method = $row['division_method'];
        $cost->division_details = $row['division_details'] ? json_decode($row['division_details'], true) : null;
        $cost->due_date = $row['due_date'];
        $cost->status = $row['status'];
        $cost->is_recurring = (bool) $row['is_recurring'];
        $cost->recurring_interval = $row['recurring_interval'];
        $cost->created_by = (int) $row['created_by'];
        $cost->created_at = $row['created_at'];
        return $cost;
    }
}
