<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\Cost;
use App\Models\CostPayment;
use App\Models\PenaltySetting;
use App\Repositories\CostRepository;
use App\Repositories\CostPaymentRepository;
use App\Repositories\PenaltySettingRepository;
use App\Utilities\FileStorage;
use App\Utilities\Validator;

final class CostService
{
    public function __construct(
        private CostRepository $costRepo = new CostRepository(),
        private CostPaymentRepository $paymentRepo = new CostPaymentRepository(),
        private PenaltySettingRepository $penaltyRepo = new PenaltySettingRepository(),
    ) {
    }

    public function createCost(array $data, int $userId): Cost
    {
        $errors = Validator::validate($data, [
            'title' => 'required',
            'amount' => 'required',
            'building_id' => 'required',
        ]);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $cost = new Cost();
        $cost->building_id = (int) $data['building_id'];
        $cost->title = $data['title'];
        $cost->description = $data['description'] ?? null;
        $cost->amount = (float) $data['amount'];
        $cost->cost_type = $data['cost_type'] ?? 'periodic';
        $cost->target_audience = $data['target_audience'] ?? 'all';
        $cost->division_method = $data['division_method'] ?? 'fixed_share';
        $cost->division_details = $data['division_details'] ?? null;
        $cost->due_date = $data['due_date'] ?? null;
        $cost->status = $data['status'] ?? 'pending';
        $cost->is_recurring = (bool) ($data['is_recurring'] ?? false);
        $cost->recurring_interval = $data['recurring_interval'] ?? 'monthly';
        $cost->created_by = $userId;

        $id = $this->costRepo->create($cost);
        $cost->id = $id;
        return $cost;
    }

    public function submitPayment(array $data, int $userId): CostPayment
    {
        $cost = $this->costRepo->findById((int) $data['cost_id']);
        if (!$cost) {
            throw new AppException('Cost not found');
        }

        $existing = $this->paymentRepo->findByCostAndUser((int) $data['cost_id'], $userId);
        if ($existing && $existing->status === 'confirmed') {
            throw new AppException('Payment already confirmed');
        }

        $payment = new CostPayment();
        $payment->cost_id = (int) $data['cost_id'];
        $payment->user_id = $userId;
        $payment->amount_paid = isset($data['amount_paid']) ? (float) $data['amount_paid'] : $cost->amount;
        $payment->status = 'upload_receipt';
        $payment->receipt_is_public = (bool) ($data['receipt_is_public'] ?? false);
        $payment->notes = $data['notes'] ?? null;

        $id = $this->paymentRepo->create($payment);
        $payment->id = $id;

        // Handle receipt upload if file provided
        if (isset($data['receipt_file']) && $data['receipt_file'] instanceof \App\Core\Request) {
            // In real scenario, handle file upload from request
        }

        return $payment;
    }

    public function uploadReceipt(int $paymentId, string $fileContent, string $originalName, int $userId, bool $isPublic = false): ?string
    {
        $payment = $this->getPaymentById($paymentId);
        if (!$payment || $payment->user_id !== $userId) {
            throw new AppException('Payment not found or access denied');
        }

        $path = FileStorage::saveReceipt($fileContent, $payment->cost_id, $paymentId, $originalName);
        if ($path) {
            // Update payment with receipt path and status
            $db = \App\Core\Database::getConnection();
            $stmt = $db->prepare("UPDATE cost_payments SET receipt_path = ?, receipt_is_public = ?, status = ? WHERE id = ?");
            $stmt->execute([$path, (int) $isPublic, 'upload_receipt', $paymentId]);
        }
        return $path;
    }

    public function confirmPayment(int $paymentId, int $managerId): bool
    {
        $updated = $this->paymentRepo->updateStatus($paymentId, 'confirmed', $managerId);
        if ($updated) {
            // After confirmation, check for penalties
            $this->applyPenaltiesIfNeeded($paymentId);
        }
        return $updated;
    }

    public function applyPenaltiesIfNeeded(int $paymentId): void
    {
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM cost_payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        $paymentRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$paymentRow) {
            return;
        }

        $costId = (int) $paymentRow['cost_id'];
        $buildingStmt = $db->prepare("SELECT building_id FROM costs WHERE id = ?");
        $buildingStmt->execute([$costId]);
        $buildingRow = $buildingStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$buildingRow) {
            return;
        }

        $buildingId = (int) $buildingRow['building_id'];
        $penalties = $this->penaltyRepo->findActiveByBuildingId($buildingId);
        if (empty($penalties)) {
            return;
        }

        // Calculate penalties based on delay from due date to confirmed at
        $confirmedAt = $paymentRow['confirmed_at'] ?? date('Y-m-d H:i:s');
        $costDue = $db->prepare("SELECT due_date FROM costs WHERE id = ?");
        $costDue->execute([$costId]);
        $dueRow = $costDue->fetch(\PDO::FETCH_ASSOC);
        $dueDate = $dueRow['due_date'] ?? $confirmedAt;

        $daysDelayed = max(0, (strtotime($confirmedAt) - strtotime($dueDate)) / 86400);

        foreach ($penalties as $penalty) {
            if ($daysDelayed >= $penalty->delay_days) {
                $amount = $penalty->penalty_type === 'percentage'
                    ? $paymentRow['amount_paid'] * ($penalty->penalty_value / 100)
                    : $penalty->penalty_value;
                // Insert penalty record
                $penaltyStmt = $db->prepare("INSERT INTO penalties (cost_payment_id, building_id, penalty_amount, reason, created_by) VALUES (?, ?, ?, ?, ?)");
                $penaltyStmt->execute([
                    $paymentId,
                    $buildingId,
                    $amount,
                    "Delayed by {$daysDelayed} days (threshold: {$penalty->delay_days})",
                    (int) $paymentRow['confirmed_by'],
                ]);
            }
        }
    }

    public function getPaymentById(int $id): ?CostPayment
    {
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM cost_payments WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->mapPaymentRow($row) : null;
    }

    private function mapPaymentRow(array $row): CostPayment
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

    public function createPenaltySetting(array $data, int $userId): PenaltySetting
    {
        $setting = new PenaltySetting();
        $setting->building_id = (int) $data['building_id'];
        $setting->penalty_type = $data['penalty_type'] ?? 'percentage';
        $setting->penalty_value = (float) $data['penalty_value'];
        $setting->delay_days = (int) ($data['delay_days'] ?? 1);
        $setting->applies_to = $data['applies_to'] ?? 'unconfirmed_payments';
        $setting->is_active = (bool) ($data['is_active'] ?? true);
        $setting->created_by = $userId;

        $id = $this->penaltyRepo->create($setting);
        $setting->id = $id;
        return $setting;
    }
}
