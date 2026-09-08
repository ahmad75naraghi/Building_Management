<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\CostPayment;
use PDO;

final class CostPaymentRepository
{
    public function create(CostPayment $payment): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO cost_payments (cost_id, user_id, unit_id, amount_paid, share_amount, status, receipt_path, receipt_is_public, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $payment->cost_id,
            $payment->user_id,
            $payment->unit_id,
            $payment->amount_paid,
            $payment->share_amount,
            $payment->status,
            $payment->receipt_path,
            (int) $payment->receipt_is_public,
            $payment->notes,
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * به‌روزرسانی پرداختِ صادرشده وقتی ساکن مبلغ/یادداشت را ثبت می‌کند
     * (به‌جای ساخت ردیف تکراری برای همان هزینه و کاربر).
     */
    public function updateSubmission(int $id, ?float $amountPaid, ?string $notes, string $status): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "UPDATE cost_payments SET amount_paid = ?, notes = ?, status = ? WHERE id = ?"
        );
        return $stmt->execute([$amountPaid, $notes, $status, $id]);
    }

    /** حذف پرداخت‌های صادرشده‌ای که هنوز اقدامی رویشان انجام نشده (برای صدور مجدد) */
    public function deleteUnactioned(int $costId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "DELETE FROM cost_payments
             WHERE cost_id = ? AND status = 'pending' AND amount_paid IS NULL AND receipt_path IS NULL"
        );
        $stmt->execute([$costId]);
        return $stmt->rowCount();
    }

    public function findByCostAndUser(int $costId, int $userId): ?CostPayment
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM cost_payments WHERE cost_id = ? AND user_id = ?");
        $stmt->execute([$costId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRow($row) : null;
    }

    public function updateStatus(int $id, string $status, ?int $confirmedBy = null): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE cost_payments SET status = ?, confirmed_by = ?, confirmed_at = CURRENT_TIMESTAMP WHERE id = ?
        ");
        return $stmt->execute([$status, $confirmedBy, $id]);
    }

    /** رد پرداخت توسط مدیر همراه با دلیل */
    public function reject(int $id, int $managerId, ?string $reason): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE cost_payments
            SET status = 'rejected', confirmed_by = ?, confirmed_at = CURRENT_TIMESTAMP, reject_reason = ?
            WHERE id = ?
        ");
        return $stmt->execute([$managerId, $reason, $id]);
    }

    public function findById(int $id): ?CostPayment
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM cost_payments WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->mapRow($row);
    }

    /**
     * ماندهٔ مالی هر واحد: جمع سهم‌های صادرشده در برابر پرداخت‌های تأییدشده.
     *
     * @return array<int, array{unit_id: int, total_share: float, total_paid: float, balance: float}>
     */
    /**
     * تعریف یکتای ماندهٔ هر واحد: بستانکاری = پرداخت تأییدشده − سهم صادرشده.
     * همهٔ صفحه‌ها (حسابداری، پروفایل ساختمان، گزارش‌ها) باید مانده را از همین متد بگیرند.
     */
    public function unitBalancesByBuilding(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT cp.unit_id AS unit_id,
                   SUM(COALESCE(cp.share_amount, 0)) AS total_share,
                   SUM(CASE WHEN cp.status = 'confirmed' THEN COALESCE(cp.amount_paid, 0) ELSE 0 END) AS total_paid
            FROM cost_payments cp
            INNER JOIN costs c ON cp.cost_id = c.id
            WHERE c.building_id = ? AND cp.unit_id IS NOT NULL AND c.deleted_at IS NULL
            GROUP BY cp.unit_id
        ");
        $stmt->execute([$buildingId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $share = (float) $row['total_share'];
            $paid = (float) $row['total_paid'];
            $out[(int) $row['unit_id']] = [
                'unit_id' => (int) $row['unit_id'],
                'total_share' => $share,
                'total_paid' => $paid,
                'balance' => round($paid - $share, 2),
            ];
        }
        return $out;
    }

    public function findByCostId(int $costId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM cost_payments WHERE cost_id = ?");
        $stmt->execute([$costId]);
        return array_map(fn($r) => $this->mapRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT cp.*, c.title AS cost_title, u.name AS user_name, u.email AS user_email,
                   un.unit_number AS unit_number
            FROM cost_payments cp
            INNER JOIN costs c ON cp.cost_id = c.id
            INNER JOIN users u ON cp.user_id = u.id
            LEFT JOIN units un ON cp.unit_id = un.id
            WHERE c.building_id = ?
            ORDER BY cp.created_at DESC, cp.id DESC
        ");
        $stmt->execute([$buildingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function mapRow(array $row): CostPayment
    {
        $p = new CostPayment();
        $p->id = (int) $row['id'];
        $p->cost_id = (int) $row['cost_id'];
        $p->user_id = (int) $row['user_id'];
        $p->amount_paid = $row['amount_paid'] !== null ? (float) $row['amount_paid'] : null;
        $p->share_amount = isset($row['share_amount']) && $row['share_amount'] !== null
            ? (float) $row['share_amount'] : null;
        $p->status = $row['status'];
        $p->receipt_path = $row['receipt_path'];
        $p->receipt_is_public = (bool) $row['receipt_is_public'];
        $p->unit_id = isset($row['unit_id']) && $row['unit_id'] !== null ? (int) $row['unit_id'] : null;
        $p->notes = $row['notes'];
        $p->reject_reason = isset($row['reject_reason']) && $row['reject_reason'] !== null ? (string) $row['reject_reason'] : null;
        $p->confirmed_by = $row['confirmed_by'] ? (int) $row['confirmed_by'] : null;
        $p->confirmed_at = $row['confirmed_at'];
        $p->created_at = $row['created_at'];
        return $p;
    }
}
