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
            INSERT INTO costs (building_id, title, description, amount, cost_type, target_audience, division_method, division_details, target_unit_ids, due_date, status, is_recurring, recurring_interval, recurring_start_date, recurring_end_date, recurring_next_date, parent_cost_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            $cost->target_unit_ids ? json_encode(array_values($cost->target_unit_ids)) : null,
            $cost->due_date,
            $cost->status,
            (int) $cost->is_recurring,
            $cost->recurring_interval,
            $cost->recurring_start_date,
            $cost->recurring_end_date,
            $cost->recurring_next_date,
            $cost->parent_cost_id,
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

    /**
     * ویرایش هزینه (فیلدهای قابل تغییر توسط مدیر).
     */
    public function update(Cost $cost): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "UPDATE costs SET
                title = ?, description = ?, amount = ?, cost_type = ?,
                target_audience = ?, division_method = ?, target_unit_ids = ?, due_date = ?
             WHERE id = ?"
        );
        return $stmt->execute([
            $cost->title,
            $cost->description,
            $cost->amount,
            $cost->cost_type,
            $cost->target_audience,
            $cost->division_method,
            $cost->target_unit_ids ? json_encode(array_values($cost->target_unit_ids)) : null,
            $cost->due_date,
            $cost->id,
        ]);
    }

    /** ثبت زمان صدور هزینه برای مخاطبان */
    public function markIssued(int $costId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE costs SET issued_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$costId]);
    }

    /** پاک‌کردن وضعیت صدور (هنگام تغییر مخاطبان پیش از صدور مجدد) */
    public function clearIssued(int $costId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE costs SET issued_at = NULL WHERE id = ?");
        return $stmt->execute([$costId]);
    }

    public function delete(int $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM costs WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /** قالب‌های دوره‌ای فعال برای تولید نمونه‌های سررسیدشده */
    public function findDueRecurringTemplates(string $today): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM costs
             WHERE cost_type = 'recurring' AND status = 'active' AND deleted_at IS NULL
               AND recurring_next_date IS NOT NULL AND recurring_next_date <= ?
             ORDER BY id"
        );
        $stmt->execute([$today]);
        return array_map(fn($r) => $this->mapRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** به‌روزرسانی نوبت بعدی و وضعیت قالب دوره‌ای */
    public function advanceRecurringTemplate(int $costId, ?string $nextDate, ?string $status = null): bool
    {
        $db = Database::getConnection();
        if ($status !== null) {
            return $db->prepare("UPDATE costs SET recurring_next_date = ?, status = ? WHERE id = ?")
                ->execute([$nextDate, $status, $costId]);
        }
        return $db->prepare("UPDATE costs SET recurring_next_date = ? WHERE id = ?")
            ->execute([$nextDate, $costId]);
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
        $cost->target_unit_ids = isset($row['target_unit_ids']) && $row['target_unit_ids']
            ? array_map('intval', (array) json_decode((string) $row['target_unit_ids'], true))
            : null;
        $cost->due_date = $row['due_date'];
        $cost->status = $row['status'];
        $cost->is_recurring = (bool) $row['is_recurring'];
        $cost->recurring_interval = $row['recurring_interval'];
        $cost->recurring_start_date = $row['recurring_start_date'] ?? null;
        $cost->recurring_end_date = $row['recurring_end_date'] ?? null;
        $cost->recurring_next_date = $row['recurring_next_date'] ?? null;
        $cost->parent_cost_id = isset($row['parent_cost_id']) && $row['parent_cost_id'] !== null ? (int) $row['parent_cost_id'] : null;
        $cost->created_by = (int) $row['created_by'];
        $cost->created_at = $row['created_at'];
        $cost->issued_at = $row['issued_at'] ?? null;
        return $cost;
    }
}
