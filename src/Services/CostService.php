<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
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
    /** مخاطبان مجاز برای هزینه: همه اعضا، ساکنین، مالکین، مستأجرین یا واحدهای خاص */
    public const AUDIENCES = ['all', 'residents', 'owners', 'tenants', 'specific_units'];

    /** تقسیم وزنی فقط بر اساس مساحت یا تعداد نفرات معنا دارد */
    private const WEIGHTED_DIVISION_METHODS = ['area', 'people_count'];

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

        $audience = $data['target_audience'] ?? 'all';
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw new ValidationException('Invalid target_audience');
        }

        // هزینه‌های «واحدهای خاص» حتماً باید حداقل یک واحد هدف داشته باشند
        $targetUnitIds = null;
        if ($audience === 'specific_units') {
            $targetUnitIds = $this->normalizeTargetUnitIds($data['target_unit_ids'] ?? null);
            if (!$targetUnitIds) {
                throw new ValidationException('target_unit_ids must list at least one unit for specific_units audience');
            }
        }

        $divMethod = $data['division_method'] ?? 'fixed_share';
        if (!in_array($divMethod, ['fixed_share', 'area', 'people_count', 'custom'], true)) {
            throw new ValidationException('Invalid division_method');
        }

        $cost = new Cost();
        $cost->building_id = (int) $data['building_id'];
        $cost->title = $data['title'];
        $cost->description = $data['description'] ?? null;
        $cost->amount = (float) $data['amount'];
        $cost->cost_type = $data['cost_type'] ?? 'periodic';
        $cost->target_audience = $audience;
        $cost->division_method = $divMethod;
        $cost->division_details = $data['division_details'] ?? null;
        $cost->target_unit_ids = $targetUnitIds;
        $cost->due_date = $data['due_date'] ?? null;
        $cost->status = $data['status'] ?? 'pending';
        $cost->is_recurring = (bool) ($data['is_recurring'] ?? false);
        $cost->recurring_interval = $data['recurring_interval'] ?? 'monthly';
        $cost->created_by = $userId;

        $id = $this->costRepo->create($cost);
        $cost->id = $id;
        \App\Core\Audit::log($userId, 'cost.create', 'cost', $id, $cost->building_id, [
            'title' => $cost->title, 'amount' => $cost->amount,
        ]);
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
        \App\Core\Audit::log($userId, 'cost.create', 'cost', $id, $cost->building_id, [
            'title' => $cost->title, 'amount' => $cost->amount,
        ]);
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
        $oldAmount = $cost->amount;
        $oldDivision = $cost->division_method;
        if (array_key_exists('target_audience', $data) || array_key_exists('target_unit_ids', $data)) {
            $newAudience = in_array($data['target_audience'] ?? $cost->target_audience, self::AUDIENCES, true)
                ? ($data['target_audience'] ?? $cost->target_audience) : $cost->target_audience;
            $newUnitIds = $newAudience === 'specific_units'
                ? $this->normalizeTargetUnitIds(array_key_exists('target_unit_ids', $data) ? $data['target_unit_ids'] : $cost->target_unit_ids)
                : null;
            if ($newAudience === 'specific_units' && !$newUnitIds) {
                throw new ValidationException('برای مخاطب «واحدهای خاص» باید حداقل یک واحد انتخاب شود.');
            }

            // مقایسه بدون توجه به ترتیب شناسه‌ها
            $storedUnitIds = $cost->target_unit_ids;
            if (is_array($newUnitIds)) {
                sort($newUnitIds);
            }
            if (is_array($storedUnitIds)) {
                sort($storedUnitIds);
            }

            if ($newAudience !== $cost->target_audience || $newUnitIds !== $storedUnitIds) {
                // اگر پرداختی انجام/ثبت شده باشد، تغییر مخاطب مجاز نیست
                $hasAction = false;
                foreach ($this->paymentRepo->findByCostId($costId) as $p) {
                    if ($p->status !== 'pending' || $p->amount_paid !== null || $p->receipt_path !== null) {
                        $hasAction = true;
                        break;
                    }
                }
                if ($hasAction) {
                    throw new AppException('مخاطبان این هزینه قابل تغییر نیستند چون پرداخت ثبت شده است.');
                }
                // ردیف‌های صادرشدهٔ بدون اقدام حذف می‌شوند تا با مخاطبان جدید دوباره صادر شود
                $this->paymentRepo->deleteUnactioned($costId);
                $this->costRepo->clearIssued($costId);
                $cost->target_audience = $newAudience;
                $cost->target_unit_ids = $newUnitIds;
                $cost->issued_at = null;
            }
        }
        if (array_key_exists('due_date', $data)) {
            $cost->due_date = !empty($data['due_date']) ? (string) $data['due_date'] : null;
        }

        // اگر مبلغ یا روش تقسیم تغییر کرد، ردیف‌های صادرشدهٔ بدون اقدام ناسازگارند و حذف می‌شوند
        if (($cost->amount !== $oldAmount || $cost->division_method !== $oldDivision)) {
            $this->paymentRepo->deleteUnactioned($costId);
            $this->costRepo->clearIssued($costId);
            $cost->issued_at = null;
        }

        $this->costRepo->update($cost);
        \App\Core\Audit::log($userId, 'cost.update', 'cost', $costId, $cost->building_id, [
            'title' => $cost->title,
        ]);
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
        $deleted = $this->costRepo->delete($costId);
        if ($deleted) {
            \App\Core\Audit::log($userId, 'cost.delete', 'cost', $costId, $cost->building_id, [
                'title' => $cost->title,
            ]);
        }
        return $deleted;
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
            Logger::warning('CostService', 'خواندن واحدها برای محاسبه شارژ ناموفق بود؛ احتمالاً مایگریشن اجرا نشده است', [
                'building_id' => $buildingId,
                'reason' => $e->getMessage(),
            ]);
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
            Logger::error('CostService', 'ساخت خودکار شارژ ماهانه انجام نشد', [
                'building_id' => $buildingId,
                'user_id' => $userId,
            ], $e);
        }
    }

    public function listPaymentsByBuilding(int $buildingId): array
    {
        return $this->paymentRepo->findByBuildingId($buildingId);
    }

    /** دریافت یک هزینه بر اساس شناسه */
    public function getCost(int $costId): ?Cost
    {
        return $this->costRepo->findById($costId);
    }

    /**
     * صدور هزینه برای مخاطبان انتخاب‌شده (جدا از شارژ ماهیانه).
     *
     * برای هر «واحد»ِ منتسب به هر پرداخت‌کننده یک ردیف پرداخت با وضعیت
     * «در انتظار» و سهم واحد ساخته می‌شود تا حسابداری بدهکار/طلبکار هر واحد
     * دقیق بماند؛ اعلان پرداخت برای هر کاربر یک‌بار (با جمع سهم واحدهایش)
     * ارسال می‌گردد. صدور، ایدمپوتنت است: اگر برای کاربری از قبل ردیفی وجود
     * داشته باشد، ردیف تکراری ساخته نمی‌شود.
     *
     * @return array{issued: int, skipped: int, total: float}
     */
    public function issueCost(int $costId, int $userId): array
    {
        $cost = $this->costRepo->findById($costId);
        if (!$cost) {
            throw new AppException('Cost not found');
        }
        if (!$this->isManager($userId, $cost->building_id)) {
            throw new AppException('فقط مدیر ساختمان می‌تواند هزینه را صادر کند.');
        }

        $payers = $this->resolvePayers($cost);
        if (!$payers) {
            throw new AppException('هیچ پرداخت‌کننده‌ای برای مخاطبان انتخاب‌شده پیدا نشد.');
        }

        $unitShares = $this->calculateUnitShares($cost, $payers);

        // گروه‌بندی ردیف‌ها بر اساس کاربر (اعلان یک‌بار برای هر کاربر)
        $byUser = [];
        foreach ($unitShares as $row) {
            $byUser[$row['user_id']][] = $row;
        }

        $issued = 0;
        $skipped = 0;
        $notifications = new NotificationService();
        foreach ($byUser as $payerUserId => $userRows) {
            // کاربری که از قبل ردیف پرداخت دارد دوباره برایش ردیف ساخته نمی‌شود
            if ($this->paymentRepo->findByCostAndUser($costId, (int) $payerUserId)) {
                $skipped += count($userRows);
                continue;
            }

            $userTotal = 0.0;
            foreach ($userRows as $row) {
                $payment = new CostPayment();
                $payment->cost_id = $costId;
                $payment->user_id = (int) $payerUserId;
                $payment->unit_id = $row['unit_id'];
                $payment->share_amount = $row['share'];
                $payment->status = 'pending';
                $this->paymentRepo->create($payment);
                $issued++;
                $userTotal += $row['share'];
            }

            try {
                $notifications->createNotification([
                    'user_id' => (int) $payerUserId,
                    'building_id' => $cost->building_id,
                    'notification_type' => 'payment',
                    'title' => 'هزینه جدید: ' . $cost->title,
                    'message' => sprintf(
                        'هزینه «%s» به مبلغ %s برای شما ثبت شده است. لطفاً پرداخت را از بخش هزینه‌ها انجام دهید.',
                        $cost->title,
                        number_format($userTotal) . ' تومان'
                    ),
                    'data' => ['cost_id' => $costId],
                ]);
            } catch (\Throwable $e) {
                // خطای اعلان نباید مانع صدور هزینه شود
                Logger::error('costs', 'خطا در ارسال اعلان صدور هزینه: ' . $e->getMessage());
            }
        }

        if ($issued > 0 || $cost->issued_at === null) {
            $this->costRepo->markIssued($costId);
        }

        \App\Core\Audit::log($userId, 'cost.issue', 'cost', $costId, $cost->building_id, [
            'title' => $cost->title, 'issued' => $issued, 'skipped' => $skipped,
        ]);

        return [
            'issued' => $issued,
            'skipped' => $skipped,
            'total' => (float) $cost->amount,
        ];
    }

    /**
     * سهم هر «واحد» از یک هزینه — مبنای حسابداری بدهکار/طلبکار واحدها.
     *
     * @param array<int, array{user_id: int, unit_ids: list<int>, weight: float, units: array<int, float>}> $payers
     * @return list<array{user_id: int, unit_id: ?int, share: float}>
     */
    private function calculateUnitShares(Cost $cost, array $payers): array
    {
        $rows = [];

        if ($cost->division_method === 'custom' && is_array($cost->division_details)) {
            $customAmounts = [];
            foreach ($cost->division_details as $key => $value) {
                if (is_array($value) && isset($value['unit_id'])) {
                    $customAmounts[(int) $value['unit_id']] = (float) ($value['amount'] ?? 0);
                } else {
                    $customAmounts[(int) $key] = (float) $value;
                }
            }
            foreach ($payers as $userId => $payer) {
                foreach ($payer['unit_ids'] as $unitId) {
                    $rows[] = ['user_id' => (int) $userId, 'unit_id' => $unitId, 'share' => round($customAmounts[$unitId] ?? 0.0, 2)];
                }
                if (!$payer['unit_ids']) {
                    $rows[] = ['user_id' => (int) $userId, 'unit_id' => null, 'share' => 0.0];
                }
            }
            return $rows;
        }

        if (in_array($cost->division_method, self::WEIGHTED_DIVISION_METHODS, true)) {
            $totalWeight = 0.0;
            foreach ($payers as $payer) {
                $totalWeight += $payer['weight'];
            }
            if ($totalWeight > 0) {
                foreach ($payers as $userId => $payer) {
                    foreach ($payer['units'] as $unitId => $unitWeight) {
                        $rows[] = [
                            'user_id' => (int) $userId,
                            'unit_id' => $unitId,
                            'share' => round($cost->amount * ($unitWeight / $totalWeight), 2),
                        ];
                    }
                    if (!$payer['units']) {
                        $rows[] = [
                            'user_id' => (int) $userId,
                            'unit_id' => null,
                            'share' => round($cost->amount * ($payer['weight'] / $totalWeight), 2),
                        ];
                    }
                }
                return $this->settleRounding($rows, $cost);
            }
            // اگر داده‌ای برای وزن‌دهی نبود به تقسیم مساوی برمی‌گردیم
        }

        // تقسیم مساوی بین همهٔ واحدها (و کاربران بدون واحد)
        $slots = 0;
        foreach ($payers as $payer) {
            $slots += max(1, count($payer['unit_ids']));
        }
        $equal = $cost->amount / max(1, $slots);
        foreach ($payers as $userId => $payer) {
            if ($payer['unit_ids']) {
                foreach ($payer['unit_ids'] as $unitId) {
                    $rows[] = ['user_id' => (int) $userId, 'unit_id' => $unitId, 'share' => round($equal, 2)];
                }
            } else {
                $rows[] = ['user_id' => (int) $userId, 'unit_id' => null, 'share' => round($equal, 2)];
            }
        }
        return $this->settleRounding($rows, $cost);
    }

    /**
     * توزیع اختلاف گردکردن روی بزرگ‌ترین سهم تا جمع ردیف‌ها دقیقاً برابر
     * مبلغ هزینه بماند (حسابداری بدون سررسید).
     *
     * @param list<array{user_id: int, unit_id: ?int, share: float}> $rows
     * @return list<array{user_id: int, unit_id: ?int, share: float}>
     */
    private function settleRounding(array $rows, Cost $cost): array
    {
        $sum = 0.0;
        $largest = null;
        foreach ($rows as $i => $row) {
            $sum += $row['share'];
            if ($largest === null || $row['share'] > $rows[$largest]['share']) {
                $largest = $i;
            }
        }
        if ($largest !== null) {
            $diff = round((float) $cost->amount - $sum, 2);
            if (abs($diff) > 0.0) {
                $rows[$largest]['share'] = round($rows[$largest]['share'] + $diff, 2);
            }
        }
        return $rows;
    }

    /**
     * تعیین پرداخت‌کنندگان یک هزینه بر اساس مخاطب انتخاب‌شده.
     *
     * @return array<int, array{user_id: int, unit_ids: list<int>, weight: float}> فهرست با کلید = شناسه کاربر
     */
    private function resolvePayers(Cost $cost): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id, unit_number, owner_user_id, tenant_user_id, owner_resident,
                    COALESCE(area, 0) AS area, COALESCE(residents_count, 0) AS residents_count
             FROM units WHERE building_id = ?"
        );
        $stmt->execute([$cost->building_id]);
        $units = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // فیلتر واحدهای هدف برای حالت «واحدهای خاص»
        if ($cost->target_audience === 'specific_units') {
            $wanted = array_fill_keys($cost->target_unit_ids ?: [], true);
            $units = array_values(array_filter($units, static fn($u) => isset($wanted[(int) $u['id']])));
        }

        $payers = [];
        $addPayer = function (int $userId, array $unit) use (&$payers, $cost) {
            $weight = match ($cost->division_method) {
                'area' => (float) $unit['area'],
                'people_count' => (float) $unit['residents_count'],
                default => 1.0,
            };
            if (!isset($payers[$userId])) {
                $payers[$userId] = ['user_id' => $userId, 'unit_ids' => [], 'weight' => 0.0, 'units' => []];
            }
            $payers[$userId]['unit_ids'][] = (int) $unit['id'];
            $payers[$userId]['weight'] += $weight;
            $payers[$userId]['units'][(int) $unit['id']] = $weight;
        };

        foreach ($units as $unit) {
            $owner = (int) ($unit['owner_user_id'] ?? 0);
            $tenant = (int) ($unit['tenant_user_id'] ?? 0);

            switch ($cost->target_audience) {
                case 'owners':
                    if ($owner > 0) {
                        $addPayer($owner, $unit);
                    }
                    break;

                case 'tenants':
                    if ($tenant > 0) {
                        $addPayer($tenant, $unit);
                    }
                    break;

                case 'residents':
                    // ساکن = مستأجر اگر هست، وگرنه مالکی که خودش ساکن واحد است
                    if ($tenant > 0) {
                        $addPayer($tenant, $unit);
                    } elseif ($owner > 0 && (bool) $unit['owner_resident']) {
                        $addPayer($owner, $unit);
                    }
                    break;

                case 'specific_units':
                    // ترجیح با مالک است؛ واحد بدون مالک به مستأجر منتسب می‌شود
                    if ($owner > 0) {
                        $addPayer($owner, $unit);
                    } elseif ($tenant > 0) {
                        $addPayer($tenant, $unit);
                    }
                    break;

                default: // all
                    if ($owner > 0) {
                        $addPayer($owner, $unit);
                    }
                    if ($tenant > 0 && $tenant !== $owner) {
                        $addPayer($tenant, $unit);
                    }
                    break;
            }
        }

        // حالت «همه اعضا»: اگر واحدی پوشش نداد، همه اعضای فعال ساختمان سهم مساوی می‌گیرند
        if ($cost->target_audience === 'all' && !$payers) {
            $stmt = $db->prepare(
                "SELECT user_id FROM building_members WHERE building_id = ? AND status = 'active'"
            );
            $stmt->execute([$cost->building_id]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $member) {
                $uid = (int) $member['user_id'];
                $payers[$uid] = ['user_id' => $uid, 'unit_ids' => [], 'weight' => 1.0, 'units' => []];
            }
        }

        return $payers;
    }

    /**
     * محاسبه سهم هر پرداخت‌کننده.
     *
     * - تقسیم مساوی (پیش‌فرض): مبلغ کل ÷ تعداد پرداخت‌کنندگان
     * - مساحت/نفرات: سهم وزنی بر اساس واحدهای مرتبط با پرداخت‌کننده
     * - مبلغ سفارشی (custom): جمع مبالغ تعیین‌شده برای واحدهای پرداخت‌کننده
     *
     * @param array<int, array{user_id: int, unit_ids: list<int>, weight: float}> $payers
     * @return array<int, float> سهم هر کاربر با کلید = شناسه کاربر
     */
    /**
     * نرمال‌سازی شناسه واحدهای هدف؛ آرایه یا رشته جداشده با کاما می‌پذیرد.
     *
     * @return list<int>|null
     */
    private function normalizeTargetUnitIds(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw), static fn($v) => trim((string) $v) !== '');
        }
        if (!is_array($raw)) {
            return null;
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if (!$ids) {
            return null;
        }
        $list = array_keys($ids);
        sort($list);
        return $list;
    }

    public function submitPayment(array $data, int $userId): CostPayment
    {
        // ردیف مشخص (پرداخت واحد-محور) یا مسیر قدیمی (هزینه + کاربر)
        $paymentId = (int) ($data['payment_id'] ?? 0);
        $costId = (int) ($data['cost_id'] ?? 0);
        if ($paymentId <= 0 && $costId <= 0) {
            throw new AppException('payment_id or cost_id is required');
        }

        $existing = null;
        if ($paymentId > 0) {
            $existing = $this->paymentRepo->findById($paymentId);
            if (!$existing || $existing->user_id !== $userId) {
                throw new AppException('Payment not found or access denied');
            }
            $costId = $existing->cost_id;
        }

        $cost = $this->costRepo->findById($costId);
        if (!$cost) {
            throw new AppException('Cost not found');
        }

        if ($existing === null) {
            $existing = $this->paymentRepo->findByCostAndUser($costId, $userId);
        }
        if ($existing && $existing->status === 'confirmed') {
            throw new AppException('Payment already confirmed');
        }

        $amountPaid = isset($data['amount_paid']) ? (float) $data['amount_paid']
            : ($existing?->share_amount ?? $cost->amount);
        $notes = $data['notes'] ?? null;

        // اگر قبلاً ردیف صادرشده‌ای برای این کاربر وجود دارد، همان به‌روز می‌شود (ردیف تکراری ساخته نمی‌شود)
        if ($existing) {
            $this->paymentRepo->updateSubmission((int) $existing->id, $amountPaid, $notes, 'upload_receipt');
            $existing->amount_paid = $amountPaid;
            $existing->notes = $notes;
            $existing->status = 'upload_receipt';
            \App\Core\Audit::log($userId, 'payment.submit', 'payment', (int) $existing->id, $cost->building_id, [
                'cost_id' => $costId, 'amount_paid' => $amountPaid, 'unit_id' => $existing->unit_id,
            ]);
            return $existing;
        }

        $payment = new CostPayment();
        $payment->cost_id = $costId;
        $payment->user_id = $userId;
        $payment->amount_paid = $amountPaid;
        $payment->status = 'upload_receipt';
        $payment->receipt_is_public = (bool) ($data['receipt_is_public'] ?? false);
        $payment->notes = $notes;

        $id = $this->paymentRepo->create($payment);
        $payment->id = $id;

        \App\Core\Audit::log($userId, 'payment.submit', 'payment', $id, $cost->building_id, [
            'cost_id' => $costId, 'amount_paid' => $amountPaid, 'unit_id' => $payment->unit_id,
        ]);

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

            $cost = $this->costRepo->findById($payment->cost_id);
            \App\Core\Audit::log($userId, 'payment.receipt', 'payment', $paymentId, $cost?->building_id, [
                'cost_id' => $payment->cost_id, 'original_name' => $originalName,
            ]);
        }
        return $path;
    }

    public function confirmPayment(int $paymentId, int $managerId): bool
    {
        // اگر مبلغ پرداختی ثبت نشده باشد (مثلاً ساکن فقط رسید آپلود کرده)،
        // هنگام تأیید سهم صادرشده به‌عنوان مبلغ پرداختی لحاظ می‌شود
        $payment = $this->getPaymentById($paymentId);
        if ($payment && $payment->amount_paid === null) {
            $fallback = $payment->share_amount;
            if ($fallback === null) {
                $cost = $this->costRepo->findById($payment->cost_id);
                $fallback = $cost?->amount;
            }
            if ($fallback !== null) {
                $this->paymentRepo->updateSubmission($paymentId, (float) $fallback, $payment->notes, $payment->status);
            }
        }

        $updated = $this->paymentRepo->updateStatus($paymentId, 'confirmed', $managerId);
        if ($updated) {
            // After confirmation, check for penalties
            $this->applyPenaltiesIfNeeded($paymentId);

            $payment = $this->getPaymentById($paymentId);
            $cost = $payment ? $this->costRepo->findById($payment->cost_id) : null;
            $buildingId = $cost?->building_id;

            \App\Core\Audit::log($managerId, 'payment.confirm', 'payment', $paymentId, $buildingId, [
                'cost_id' => $payment?->cost_id, 'amount_paid' => $payment?->amount_paid, 'unit_id' => $payment?->unit_id,
            ]);

            // اطلاع به پرداخت‌کننده که پرداختش نشست و به حساب واحد شد
            if ($payment) {
                try {
                    (new NotificationService())->createNotification([
                        'user_id' => $payment->user_id,
                        'building_id' => $buildingId,
                        'notification_type' => 'payment',
                        'title' => 'پرداخت شما تأیید شد',
                        'message' => sprintf(
                            'پرداخت %s بابت هزینه «%s» تأیید و به حساب واحد ثبت شد.',
                            number_format((float) ($payment->amount_paid ?? 0)) . ' تومان',
                            $cost?->title ?? ''
                        ),
                        'data' => ['cost_id' => $payment->cost_id],
                    ]);
                } catch (\Throwable $e) {
                    Logger::error('costs', 'خطا در ارسال اعلان تأیید پرداخت: ' . $e->getMessage());
                }
            }
        }
        return $updated;
    }

    /**
     * رد پرداخت توسط مدیر (وقتی مبلغ به حساب نیامده). پرداخت‌کننده دلیل را
     * می‌بیند و می‌تواند دوباره پرداخت/رسید جدید ثبت کند.
     */
    public function rejectPayment(int $paymentId, int $managerId, ?string $reason): bool
    {
        $payment = $this->getPaymentById($paymentId);
        if (!$payment) {
            throw new AppException('Payment not found');
        }
        $cost = $this->costRepo->findById($payment->cost_id);
        if (!$cost) {
            throw new AppException('Cost not found');
        }
        if (!$this->isManager($managerId, $cost->building_id)) {
            throw new AppException('فقط مدیر ساختمان می‌تواند پرداخت را رد کند.');
        }
        if ($payment->status === 'confirmed') {
            throw new AppException('پرداخت تأییدشده قابل رد نیست.');
        }

        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new ValidationException('دلیل رد پرداخت الزامی است.');
        }

        $rejected = $this->paymentRepo->reject($paymentId, $managerId, $reason);

        if ($rejected) {
            \App\Core\Audit::log($managerId, 'payment.reject', 'payment', $paymentId, $cost->building_id, [
                'cost_id' => $payment->cost_id, 'reason' => $reason, 'unit_id' => $payment->unit_id,
            ]);

            try {
                (new NotificationService())->createNotification([
                    'user_id' => $payment->user_id,
                    'building_id' => $cost->building_id,
                    'notification_type' => 'payment',
                    'title' => 'پرداخت شما رد شد',
                    'message' => sprintf(
                        'پرداخت شما بابت هزینه «%s» رد شد. دلیل: %s — لطفاً پس از پرداخت واقعی، دوباره رسید ثبت کنید.',
                        $cost->title,
                        $reason
                    ),
                    'data' => ['cost_id' => $payment->cost_id],
                ]);
            } catch (\Throwable $e) {
                Logger::error('costs', 'خطا در ارسال اعلان رد پرداخت: ' . $e->getMessage());
            }
        }

        return $rejected;
    }

    /**
     * ماندهٔ مالی هر واحد ساختمان: جمع سهم‌های صادرشده در برابر پرداخت‌های
     * تأییدشده. ماندهٔ منفی یعنی واحد «بدهکار» و مثبت یعنی «طلبکار».
     *
     * @return array<int, array{unit_id: int, total_share: float, total_paid: float, balance: float, state: string}>
     */
    public function getUnitBalances(int $buildingId, int $userId): array
    {
        if (!$this->isBuildingMember($userId, $buildingId)) {
            throw new AppException('شما عضو این ساختمان نیستید.');
        }
        $balances = $this->paymentRepo->unitBalancesByBuilding($buildingId);
        foreach ($balances as &$b) {
            $b['state'] = $b['balance'] < 0 ? 'debtor' : ($b['balance'] > 0 ? 'creditor' : 'settled');
        }
        unset($b);
        return $balances;
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
        $penaltyType = (string) ($data['penalty_type'] ?? $data['type'] ?? 'percentage');
        if ($penaltyType === 'fixed') {
            $penaltyType = 'fixed_amount'; // نام مستعار فرانت‌اند
        }
        $setting->penalty_type = $penaltyType;
        $setting->penalty_value = (float) ($data['penalty_value'] ?? $data['amount'] ?? 0);
        $setting->delay_days = (int) ($data['delay_days'] ?? 1);
        $setting->applies_to = $data['applies_to'] ?? 'unconfirmed_payments';
        $setting->is_active = (bool) ($data['is_active'] ?? true);
        $setting->created_by = $userId;

        $id = $this->penaltyRepo->create($setting);
        $setting->id = $id;
        \App\Core\Audit::log($userId, 'penalty_setting.create', 'penalty_setting', $id, $setting->building_id, []);
        return $setting;
    }

    /**
     * لیست تنظیم‌های جریمه ساختمان — برای همه اعضای فعال ساختمان قابل مشاهده است.
     */
    public function listPenaltySettings(int $buildingId, int $userId): array
    {
        if (!$this->isBuildingMember($userId, $buildingId)) {
            throw new AppException('شما عضو این ساختمان نیستید.');
        }
        return $this->penaltyRepo->findByBuildingId($buildingId);
    }

    /**
     * ویرایش تنظیم جریمه — فقط مدیر ساختمان.
     * مانند ثبت، هم کلیدهای اصلی (penalty_type/penalty_value) و هم نام‌های مستعار
     * مستندات (type/amount) پذیرفته می‌شود.
     */
    public function updatePenaltySetting(int $settingId, array $data, int $userId): PenaltySetting
    {
        $setting = $this->penaltyRepo->findById($settingId);
        if ($setting === null) {
            throw new AppException('Penalty setting not found');
        }
        if (!$this->isManager($userId, $setting->building_id)) {
            throw new AppException('فقط مدیر ساختمان می‌تواند تنظیم جریمه را تغییر دهد.');
        }

        if (isset($data['penalty_type']) || isset($data['type'])) {
            $type = (string) ($data['penalty_type'] ?? $data['type']);
            if ($type === 'fixed') {
                $type = 'fixed_amount'; // نام مستعار سازگار با مستندات قدیمی
            }
            if (!in_array($type, ['percentage', 'fixed_amount'], true)) {
                throw new ValidationException('نوع جریمه باید percentage یا fixed_amount باشد.');
            }
            $setting->penalty_type = $type;
        }
        if (isset($data['penalty_value']) || isset($data['amount'])) {
            $value = (float) ($data['penalty_value'] ?? $data['amount']);
            if ($value < 0) {
                throw new ValidationException('مقدار جریمه نمی‌تواند منفی باشد.');
            }
            $setting->penalty_value = $value;
        }
        if (isset($data['delay_days'])) {
            $setting->delay_days = max(0, (int) $data['delay_days']);
        }
        if (isset($data['applies_to'])) {
            $setting->applies_to = (string) $data['applies_to'];
        }
        if (array_key_exists('is_active', $data)) {
            $setting->is_active = (bool) $data['is_active'];
        }

        $this->penaltyRepo->update($setting);
        \App\Core\Audit::log($userId, 'penalty_setting.update', 'penalty_setting', $settingId, $setting->building_id, []);
        return $setting;
    }

    /**
     * حذف تنظیم جریمه — فقط مدیر ساختمان.
     */
    public function deletePenaltySetting(int $settingId, int $userId): bool
    {
        $setting = $this->penaltyRepo->findById($settingId);
        if ($setting === null) {
            throw new AppException('Penalty setting not found');
        }
        if (!$this->isManager($userId, $setting->building_id)) {
            throw new AppException('فقط مدیر ساختمان می‌تواند تنظیم جریمه را حذف کند.');
        }
        $deleted = $this->penaltyRepo->delete($settingId);
        if ($deleted) {
            \App\Core\Audit::log($userId, 'penalty_setting.delete', 'penalty_setting', $settingId, $setting->building_id, []);
        }
        return $deleted;
    }

    private function isBuildingMember(int $userId, int $buildingId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT 1 FROM building_members WHERE user_id = ? AND building_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$userId, $buildingId]);
        return (bool) $stmt->fetchColumn();
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
