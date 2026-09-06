<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\Cost;
use App\Models\CostPayment;
use App\Models\PenaltySetting;
use App\Repositories\BuildingRepository;
use App\Repositories\CostRepository;
use App\Core\Database;
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

    /**
     * ثبت شارژ ماهیانه ثابت ساختمان برای ماه جاری.
     * اگر شارژ این ماه قبلاً ثبت شده باشد، همان رکورد برگردانده می‌شود.
     *
     * @throws AppException اگر شارژ ثابت تنظیم نشده باشد
     */
    public function createMonthlyCharge(int $buildingId, int $userId): Cost
    {
        $building = (new BuildingRepository())->findById($buildingId);
        if (!$building) {
            throw new AppException('ساختمان یافت نشد.');
        }
        if (!$building->monthly_charge_enabled) {
            throw new AppException('شارژ ماهیانه برای این ساختمان فعال نیست. ابتدا آن را در صفحه مالی تنظیم کنید.');
        }

        $breakdown = $this->calculateMonthlyCharges($buildingId);
        if ($breakdown['total'] <= 0) {
            throw new AppException($this->chargeConfigHint($building->charge_mode));
        }

        $monthKey = date('Y-m');
        $marker = 'auto:monthly:' . $monthKey;

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM costs WHERE building_id = ? AND description = ? LIMIT 1");
        $stmt->execute([$buildingId, $marker]);
        $existingId = $stmt->fetchColumn();
        if ($existingId) {
            $existing = $this->costRepo->findById((int) $existingId);
            if ($existing) {
                return $existing;
            }
        }

        $cost = new Cost();
        $cost->building_id = $buildingId;
        $cost->title = 'شارژ ماهیانه ' . $monthKey;
        $cost->description = $marker;
        $cost->amount = $breakdown['total'];
        $cost->cost_type = 'periodic';
        $cost->target_audience = 'all';
        $cost->division_method = self::CHARGE_MODE_DIVISION[$building->charge_mode] ?? 'fixed_share';
        // جزئیات تقسیم (سهم هر واحد) برای شفافیت ذخیره می‌شود
        $cost->division_details = $breakdown['units'];
        $cost->due_date = date('Y-m-t');
        $cost->status = 'pending';
        $cost->is_recurring = true;
        $cost->recurring_interval = 'monthly';
        $cost->created_by = $userId;

        $id = $this->costRepo->create($cost);
        $cost->id = $id;
        return $cost;
    }

    /** نگاشت حالت شارژ به روش تقسیم هزینه */
    private const CHARGE_MODE_DIVISION = [
        'fixed' => 'fixed_share',
        'per_person' => 'people_count',
        'custom' => 'custom',
    ];

    /**
     * پیام راهنما وقتی تنظیمات شارژ ناقص است.
     */
    private function chargeConfigHint(string $mode): string
    {
        switch ($mode) {
            case 'per_person':
                return 'شارژ نفری محاسبه نشد. «مبلغ به‌ازای هر نفر» را تنظیم کنید و تعداد نفرات واحدها را در صفحه واحدها وارد کنید.';
            case 'custom':
                return 'شارژ دلخواه محاسبه نشد. برای حداقل یک واحد «شارژ اختصاصی» تعیین کنید.';
            default:
                return 'مبلغ شارژ ثابت ماهیانه تنظیم نشده است. ابتدا مبلغ را ذخیره کنید.';
        }
    }

    /**
     * آیا کاربر مدیر این ساختمان است؟
     */
    private function isManager(int $userId, int $buildingId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id FROM building_members
             WHERE user_id = ? AND building_id = ? AND role = 'manager' AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$userId, $buildingId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * ویرایش هزینه — فقط مدیر ساختمان.
     */
    public function updateCost(int $costId, array $data, int $userId): ?Cost
    {
        $cost = $this->costRepo->findById($costId);
        if (!$cost) {
            return null;
        }
        if (!$this->isManager($userId, $cost->building_id)) {
            throw new AppException('فقط مدیر ساختمان می‌تواند هزینه‌ها را ویرایش کند.');
        }

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw new ValidationException('عنوان هزینه نمی‌تواند خالی باشد.');
            }
            $cost->title = $title;
        }
        if (array_key_exists('amount', $data)) {
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw new ValidationException('مبلغ هزینه باید بزرگ‌تر از صفر باشد.');
            }
            $cost->amount = $amount;
        }
        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            // توضیح داخلی شارژ خودکار نباید پاک شود
            if (!str_starts_with((string) $cost->description, 'auto:monthly:')) {
                $cost->description = $description !== '' ? $description : null;
            }
        }
        if (array_key_exists('cost_type', $data)) {
            $cost->cost_type = in_array($data['cost_type'], ['periodic', 'one_time'], true)
                ? $data['cost_type'] : $cost->cost_type;
        }
        if (array_key_exists('division_method', $data)) {
            $cost->division_method = in_array($data['division_method'], ['fixed_share', 'area', 'people_count', 'custom'], true)
                ? $data['division_method'] : $cost->division_method;
        }
        if (array_key_exists('target_audience', $data)) {
            $cost->target_audience = in_array($data['target_audience'], ['all', 'owners', 'tenants', 'residents'], true)
                ? $data['target_audience'] : $cost->target_audience;
        }
        if (array_key_exists('due_date', $data)) {
            $cost->due_date = !empty($data['due_date']) ? (string) $data['due_date'] : null;
        }

        $this->costRepo->update($cost);
        return $this->costRepo->findById($costId);
    }

    /**
     * حذف هزینه — فقط مدیر ساختمان.
     */
    public function deleteCost(int $costId, int $userId): bool
    {
        $cost = $this->costRepo->findById($costId);
        if (!$cost) {
            return false;
        }
        if (!$this->isManager($userId, $cost->building_id)) {
            throw new AppException('فقط مدیر ساختمان می‌تواند هزینه‌ها را حذف کند.');
        }
        return $this->costRepo->delete($costId);
    }

    /**
     * محاسبه شارژ ماهیانه هر واحد بر اساس حالت انتخابی ساختمان.
     *
     * حالت‌ها:
     *   fixed       مبلغ ثابت برای هر واحد (monthly_charge)
     *   per_person  تعداد نفرات ساکن واحد × نرخ هر نفر (charge_per_person)
     *   custom      مبلغ اختصاصی هر واحد (units.custom_charge)
     *
     * @return array{mode: string, total: float, units: list<array{unit_id:int, unit_number:string, residents_count:int, amount:float}>}
     */
    public function calculateMonthlyCharges(int $buildingId): array
    {
        $building = (new BuildingRepository())->findById($buildingId);
        if (!$building) {
            return ['mode' => 'fixed', 'total' => 0.0, 'units' => []];
        }

        $mode = $building->charge_mode ?: 'fixed';
        $db = Database::getConnection();

        try {
            $stmt = $db->prepare(
                "SELECT id, unit_number, residents_count, custom_charge
                 FROM units WHERE building_id = ? ORDER BY unit_number ASC"
            );
            $stmt->execute([$buildingId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // ستون‌های جدید هنوز مایگریت نشده‌اند
            error_log('[CostService] calculateMonthlyCharges fallback: ' . $e->getMessage());
            $rows = [];
        }

        $units = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $residents = (int) ($row['residents_count'] ?? 0);
            switch ($mode) {
                case 'per_person':
                    $amount = $residents * (float) $building->charge_per_person;
                    break;
                case 'custom':
                    $amount = $row['custom_charge'] !== null ? (float) $row['custom_charge'] : 0.0;
                    break;
                default:
                    $amount = (float) $building->monthly_charge;
            }
            if ($amount <= 0) {
                continue;
            }
            $units[] = [
                'unit_id' => (int) $row['id'],
                'unit_number' => (string) $row['unit_number'],
                'residents_count' => $residents,
                'amount' => $amount,
            ];
            $total += $amount;
        }

        // اگر هنوز واحدی ثبت نشده، در حالت ثابت دست‌کم مبلغ پایه لحاظ می‌شود
        if (empty($units) && $mode === 'fixed' && $building->monthly_charge > 0) {
            $total = (float) $building->monthly_charge;
        }

        return ['mode' => $mode, 'total' => $total, 'units' => $units];
    }

    /**
     * اطمینان از وجود شارژ ماه جاری (فراخوانی خودکار هنگام مشاهده مالی).
     * خطاها نادیده گرفته می‌شوند تا نمایش صفحه متوقف نشود.
     */
    public function ensureMonthlyCharge(int $buildingId, int $userId): void
    {
        try {
            $building = (new BuildingRepository())->findById($buildingId);
            if (!$building || !$building->monthly_charge_enabled) {
                return;
            }
            // بسته به حالت شارژ، تنظیمات لازم باید کامل باشد
            if ($this->calculateMonthlyCharges($buildingId)['total'] <= 0) {
                return;
            }
            $monthKey = date('Y-m');
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id FROM costs WHERE building_id = ? AND description = ? LIMIT 1");
            $stmt->execute([$buildingId, 'auto:monthly:' . $monthKey]);
            if (!$stmt->fetchColumn()) {
                $this->createMonthlyCharge($buildingId, $userId);
            }
        } catch (\Throwable $e) {
            error_log('[CostService] ensureMonthlyCharge skipped: ' . $e->getMessage());
        }
    }

    public function listPaymentsByBuilding(int $buildingId): array
    {
        return $this->paymentRepo->findByBuildingId($buildingId);
    }

    public function submitPayment(array $data, int $userId): CostPayment
    {
        $costId = (int) ($data['cost_id'] ?? 0);
        if ($costId <= 0) {
            throw new AppException('cost_id is required');
        }
        $cost = $this->costRepo->findById($costId);
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
            $db = \App\Core\Database::getConnection();

            // Update payment with receipt path and status
            $stmt = $db->prepare("UPDATE cost_payments SET receipt_path = ?, receipt_is_public = ?, status = ? WHERE id = ?");
            $stmt->execute([$path, (int) $isPublic, 'upload_receipt', $paymentId]);

            // ثبت متادیتای رسید در جدول receipts (cost_payment_id یکتاست)
            $mime = null;
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = finfo_buffer($finfo, $fileContent);
                    finfo_close($finfo);
                }
            }
            $receiptStmt = $db->prepare(
                "INSERT INTO receipts (cost_payment_id, file_path, file_size, mime_type, original_name, is_public)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     file_path = VALUES(file_path),
                     file_size = VALUES(file_size),
                     mime_type = VALUES(mime_type),
                     original_name = VALUES(original_name),
                     is_public = VALUES(is_public)"
            );
            $receiptStmt->execute([
                $paymentId,
                $path,
                strlen($fileContent),
                $mime,
                $originalName,
                (int) $isPublic,
            ]);
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
        // سازگاری با مستندات API: هم `penalty_type/penalty_value` و هم `type/amount` پذیرفته می‌شود
        $setting->penalty_type = $data['penalty_type'] ?? $data['type'] ?? 'percentage';
        $setting->penalty_value = (float) ($data['penalty_value'] ?? $data['amount'] ?? 0);
        $setting->delay_days = (int) ($data['delay_days'] ?? 1);
        $setting->applies_to = $data['applies_to'] ?? 'unconfirmed_payments';
        $setting->is_active = (bool) ($data['is_active'] ?? true);
        $setting->created_by = $userId;

        $id = $this->penaltyRepo->create($setting);
        $setting->id = $id;
        return $setting;
    }

    public function listCostsByBuilding(int $buildingId): array
    {
        return $this->costRepo->findByBuildingId($buildingId);
    }

    /**
     * Aggregate financial overview for a building (used by the dashboard).
     *
     * @return array{
     *     building_id: int,
     *     total_costs: float,
     *     total_collected: float,
     *     total_remaining: float,
     *     collection_percentage: float,
     *     costs_count: int,
     *     payments_count: int,
     *     confirmed_count: int
     * }
     */
    public function getFinancialSummary(int $buildingId): array
    {
        $costs = $this->costRepo->findByBuildingId($buildingId);

        $totalCosts = 0.0;
        $totalCollected = 0.0;
        $paymentsCount = 0;
        $confirmedCount = 0;

        foreach ($costs as $cost) {
            $totalCosts += $cost->amount;
            foreach ($this->paymentRepo->findByCostId($cost->id) as $payment) {
                $paymentsCount++;
                if ($payment->status === 'confirmed') {
                    $totalCollected += (float) $payment->amount_paid;
                    $confirmedCount++;
                }
            }
        }

        $totalRemaining = max(0.0, $totalCosts - $totalCollected);
        $collectionPercentage = $totalCosts > 0.0
            ? round(($totalCollected / $totalCosts) * 100, 1)
            : 0.0;

        return [
            'building_id' => $buildingId,
            'total_costs' => $totalCosts,
            'total_collected' => $totalCollected,
            'total_remaining' => $totalRemaining,
            'collection_percentage' => $collectionPercentage,
            'costs_count' => count($costs),
            'payments_count' => $paymentsCount,
            'confirmed_count' => $confirmedCount,
        ];
    }
}
