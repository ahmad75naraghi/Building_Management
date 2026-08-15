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
            INSERT INTO cost_payments (cost_id, user_id, amount_paid, status, receipt_path, receipt_is_public, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $payment->cost_id,
            $payment->user_id,
            $payment->amount_paid,
            $payment->status,
            $payment->receipt_path,
            (int) $payment->receipt_is_public,
            $payment->notes,
        ]);
        return (int) $db->lastInsertId();
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

    public function findByCostId(int $costId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM cost_payments WHERE cost_id = ?");
        $stmt->execute([$costId]);
        return array_map(fn($r) => $this->mapRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapRow(array $row): CostPayment
    {
        $p = new CostPayment();
        $p->id = (int) $row['id'];
        $p->cost_id = (int) $row['cost_id'];
        $p->user_id = (int) $row['user_id'];
        $p->amount_paid = $row['amount_paid'] !== null ? (float) $row['amount_paid'] : null;
        $p->status = $row['status'];
        $p->receipt_path = $row['receipt_path'];
        $p->receipt_is_public = (bool) $row['receipt_is_public'];
        $p->notes = $row['notes'];
        $p->confirmed_by = $row['confirmed_by'] ? (int) $row['confirmed_by'] : null;
        $p->confirmed_at = $row['confirmed_at'];
        $p->created_at = $row['created_at'];
        return $p;
    }
}
