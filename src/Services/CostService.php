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
use App\Utilities\JalaliHelper;
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

        // هزینهٔ دوره‌ای با تناوب مشخص → به‌صورت «قالب» ذخیره می‌شود؛
        // کران روزانه در هر نوبت یک نمونهٔ قابل‌پرداخت می‌سازد و صادر می‌کند.
        if ($cost->is_recurring) {
            $interval = (string) ($data['recurring_interval'] ?? 'monthly');
            if (!array_key_exists($interval, self::RECURRING_INTERVALS)) {
                throw new ValidationException('تناوب تکرار نامعتبر است.');
            }
            $start = trim((string) ($data['recurring_start_date'] ?? date('Y-m-d')));
            if (strtotime($start) === false) {
                throw new ValidationException('تاریخ شروع دوره معتبر نیست.');
            }
            $end = trim((string) ($data['recurring_end_date'] ?? ''));
            if ($end !== '' && (strtotime($end) === false || strtotime($end) < strtotime($start))) {
                throw new ValidationException('تاریخ پایان دوره باید پس از تاریخ شروع آن باشد.');
            }
            $cost->cost_type = 'recurring';
            $cost->status = 'active';
            $cost->recurring_interval = $interval;
            $cost->recurring_start_date = date('Y-m-d', strtotime($start));
            $cost->recurring_end_date = $end !== '' ? date('Y-m-d', strtotime($end)) : null;
            $cost->recurring_next_date = $cost->recurring_start_date;
        }

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
        'combined' => 'combined',
    ];

    /**
     * پیام راهنما وقتی تنظیمات شارژ ناقص است.
     */
    private function chargeConfigHint(string $mode): string
    {
        switch ($mode) {
            case 'per_person':
                return 'شارژ نفری محاسبه نشد. «مبلغ به‌ازای هر نفر» را تنظیم کنید و تعداد نفرات واحدها را در صفحه واحدها وارد کنید.';
            case 'combined':
                return 'شارژ ترکیبی محاسبه نشد. «مبلغ ثابت» و «مبلغ هر نفر» را تنظیم کنید و تعداد نفرات واحدها را در صفحه واحدها وارد کنید.';
            case 'custom':
                return 'شارژ دلخواه محاسبه نشد. برای حداقل یک واحد «شارژ اختصاصی» تعیین کنید.';
            default:
                return 'مبلغ شارژ ثابت ماهیانه تنظیم نشده است. ابتدا مبلغ را ذخیره کنید.';
        }
    }

    // ================================================================
    //  هزینه‌های دوره‌ای با تناوب دلخواه + کران روزانه
    // ================================================================

    /** تناوب‌های مجاز هزینهٔ دوره‌ای */
    public const RECURRING_INTERVALS = [
        'weekly' => 'هفتگی (هر هفته)',
        'biweekly' => 'دو هفته یک‌بار',
        'monthly' => 'ماهانه',
        'bimonthly' => 'دو ماه یک‌بار',
        'quarterly' => 'سه ماه یک‌بار',
        'yearly' => 'سالانه',
    ];

    /** حداکثر نمونه‌سازی عقب‌افتاده برای یک قالب (جلوگیری از حلقهٔ بی‌پایان) */
    private const MAX_CATCHUP = 24;

    public static function intervalLabel(?string $interval): string
    {
        return self::RECURRING_INTERVALS[$interval ?? ''] ?? 'ماهانه';
    }

    /** افزودن یک تناوب به تاریخ (Y-m-d) و بازگشت تاریخ جدید */
    public static function addInterval(string $date, string $interval): string
    {
        $d = new \DateTime(substr($date, 0, 10));
        switch ($interval) {
            case 'weekly':
                $d->modify('+1 week');
                break;
            case 'biweekly':
                $d->modify('+2 weeks');
                break;
            case 'bimonthly':
                $d->modify('+2 months');
                break;
            case 'quarterly':
                $d->modify('+3 months');
                break;
            case 'yearly':
                $d->modify('+1 year');
                break;
            default:
                $d->modify('+1 month');
        }
        return $d->format('Y-m-d');
    }

    /**
     * تولید و صدور نمونه‌های سررسیدشدهٔ هزینه‌های دوره‌ای.
     * کاملاً توان‌تکرار (idempotent): هر نوبت با نشانگر یکتا ثبت می‌شود.
     *
     * @return array{generated:int, ended:int, details:list<array<string, mixed>>}
     */
    public function generateDueRecurringCosts(?string $today = null): array
    {
        $today = date('Y-m-d', strtotime($today ?: date('Y-m-d')));
        $templates = $this->costRepo->findDueRecurringTemplates($today);
        return $this->processDueRecurringTemplates($templates, $today);
    }

    /**
     * اجرای موتور دوره‌ای فقط برای یک ساختمان (دکمهٔ دستی در صفحهٔ حسابداری).
     * برخلاف نسخهٔ سراسری، فقط قالب‌های همان ساختمان پردازش می‌شوند تا
     * اجرای دستی سریع و قابل‌اتکا بماند.
     *
     * @return array{generated:int, ended:int, details:array<int,array<string,mixed>>}
     */
    public function generateDueRecurringCostsForBuilding(int $buildingId, ?string $today = null): array
    {
        $today = date('Y-m-d', strtotime($today ?: date('Y-m-d')));
        $templates = $this->costRepo->findDueRecurringTemplates($today, $buildingId);
        return $this->processDueRecurringTemplates($templates, $today);
    }

    /** حلقهٔ مشترک صدور نوبت‌های سررسیدشده برای فهرستی از قالب‌های دوره‌ای */
    private function processDueRecurringTemplates(array $templates, string $today): array
    {
        $generated = 0;
        $ended = 0;
        $details = [];

        foreach ($templates as $tpl) {
            $next = $tpl->recurring_next_date;
            $guard = 0;
            $stuck = false; // صدور ناموفق: نوبت جلو نمی‌رود تا در کران بعدی دوباره تلاش شود

            while ($next !== null && $next <= $today && $guard < self::MAX_CATCHUP) {
                $guard++;
                // پایان دوره؟
                if ($tpl->recurring_end_date !== null && $next > $tpl->recurring_end_date) {
                    $next = null;
                    break;
                }
                $marker = 'auto:recurring:' . $tpl->id . ':' . $next;
                if (!$this->costExistsWithDescription((int) $tpl->building_id, $marker)) {
                    $child = new Cost();
                    $child->building_id = (int) $tpl->building_id;
                    $child->title = $tpl->title . ' (نوبت ' . $next . ')';
                    $child->description = $marker;
                    $child->amount = $tpl->amount;
                    $child->cost_type = 'periodic';
                    $child->target_audience = $tpl->target_audience;
                    $child->division_method = $tpl->division_method;
                    $child->target_unit_ids = $tpl->target_unit_ids;
                    $child->due_date = $next;
                    $child->status = 'pending';
                    $child->is_recurring = false;
                    $child->recurring_interval = null;
                    $child->parent_cost_id = $tpl->id;
                    $child->created_by = (int) $tpl->created_by;
                    $childId = $this->costRepo->create($child);

                    try {
                        $this->issueCost((int) $childId, (int) $tpl->created_by);
                        $generated++;
                        $details[] = ['template_id' => $tpl->id, 'cost_id' => $childId, 'period' => $next];
                    } catch (\Throwable $e) {
                        Logger::warning('CostService', 'صدور نمونهٔ دوره‌ای ناموفق بود', [
                            'template_id' => $tpl->id, 'period' => $next, 'reason' => $e->getMessage(),
                        ]);
                        // نمونهٔ ساخته‌شده حذف می‌شود و نوبت سررسید ثابت می‌ماند تا دوباره تلاش شود
                        $this->costRepo->delete((int) $childId);
                        $stuck = true;
                        break;
                    }
                }
                $next = self::addInterval($next, (string) $tpl->recurring_interval);
            }

            if ($stuck) {
                // هیچ پیشرفتی حاصل نشد؛ چیزی تغییر نمی‌کند
                continue;
            }
            if ($next === null) {
                $this->costRepo->advanceRecurringTemplate((int) $tpl->id, null, 'ended');
                $ended++;
            } elseif ($tpl->recurring_end_date !== null && $next > $tpl->recurring_end_date) {
                // دوره تمام شده؛ حتی اگر نوبت بعد در آینده باشد، قالب بسته می‌شود
                $this->costRepo->advanceRecurringTemplate((int) $tpl->id, null, 'ended');
                $ended++;
            } else {
                $this->costRepo->advanceRecurringTemplate((int) $tpl->id, $next);
            }
        }

        if ($generated > 0 || $ended > 0) {
            // کاربر ۰ = سیستم (کران)
            \App\Core\Audit::log(0, 'cron.recurring', null, null, null, [
                'generated' => $generated, 'ended' => $ended, 'date' => $today,
            ]);
        }
        return ['generated' => $generated, 'ended' => $ended, 'details' => $details];
    }

    /**
     * کران: اطمینان از صدور شارژ ماه جاری برای همهٔ ساختمان‌های فعال.
     *
     * @return array{created:int, skipped:int}
     */
    public function generateAllMonthlyCharges(): array
    {
        $db = Database::getConnection();
        $rows = $db->query(
            "SELECT b.id AS building_id
             FROM buildings b
             WHERE b.monthly_charge_enabled = 1 AND b.deleted_at IS NULL"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $created = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $res = $this->generateMonthlyChargeForBuilding((int) $r['building_id']);
            $created += $res['created'];
            $skipped += $res['skipped'];
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * صدور شارژ ماه جاری برای یک ساختمان مشخص (نسخهٔ تک‌ساختمانِ موتور دوره‌ای).
     *
     * @return array{created:int, skipped:int}
     */
    public function generateMonthlyChargeForBuilding(int $buildingId): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT bm.user_id FROM building_members bm
             WHERE bm.building_id = ? AND bm.role = 'manager' AND bm.status = 'active'
             ORDER BY bm.id LIMIT 1"
        );
        $stmt->execute([$buildingId]);
        $managerId = (int) ($stmt->fetchColumn() ?: 0);
        if ($managerId <= 0) {
            return ['created' => 0, 'skipped' => 1];
        }
        try {
            $cost = $this->createMonthlyCharge($buildingId, $managerId);
            if ($cost->status === 'pending' && empty($cost->issued_at)) {
                $this->issueCost((int) $cost->id, $managerId);
                return ['created' => 1, 'skipped' => 0];
            }
            return ['created' => 0, 'skipped' => 1];
        } catch (\Throwable $e) {
            Logger::warning('CostService', 'کران شارژ ماهیانه برای ساختمان ناموفق بود', [
                'building_id' => $buildingId, 'reason' => $e->getMessage(),
            ]);
            return ['created' => 0, 'skipped' => 1];
        }
    }

    /** آیا هزینه‌ای با این نشانگر در ساختمان هست؟ */
    private function costExistsWithDescription(int $buildingId, string $description): bool
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT id FROM costs WHERE building_id = ? AND description = ? LIMIT 1"
        );
        $stmt->execute([$buildingId, $description]);
        return (bool) $stmt->fetchColumn();
    }

    // ================================================================
    //  ثبت مستقیم توسط مدیر (بدون درخواست ساکن)
    // ================================================================

    /** آیا کاربر مدیر فعال این ساختمان است؟ (عمومی برای کنترلرها) */
    public function isBuildingManager(int $userId, int $buildingId): bool
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT id FROM building_members
             WHERE user_id = ? AND building_id = ? AND role = 'manager' AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$userId, $buildingId]);
        return (bool) $stmt->fetchColumn();
    }

    private function requireBuildingManager(int $userId, int $buildingId): void
    {
        if (!$this->isBuildingManager($userId, $buildingId)) {
            throw new AppException('فقط مدیر ساختمان می‌تواند این کار را انجام دهد.');
        }
    }

    /** واحد باید متعلق به همان ساختمان باشد؛ شناسهٔ ساکن (مستأجر وگرنه مالک) برمی‌گردد */
    private function resolveUnitInBuilding(int $buildingId, int $unitId): array
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT id, unit_number, owner_user_id, tenant_user_id FROM units WHERE id = ? AND building_id = ? LIMIT 1"
        );
        $stmt->execute([$unitId, $buildingId]);
        $unit = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$unit) {
            throw new AppException('واحد در این ساختمان پیدا نشد.');
        }
        return $unit;
    }

    /**
     * ثبت مستقیم پرداخت برای یک واحد توسط مدیر (بدون درخواست ساکن).
     * پرداخت بلافاصله تأیید می‌شود و به حساب واحد می‌نشیند (بدهی کم / طلب زیاد).
     */
    public function recordDirectPayment(int $buildingId, int $unitId, float $amount, ?string $notes, int $managerId): CostPayment
    {
        $this->requireBuildingManager($managerId, $buildingId);
        if ($amount <= 0) {
            throw new ValidationException('مبلغ پرداخت باید بیشتر از صفر باشد.');
        }
        $unit = $this->resolveUnitInBuilding($buildingId, $unitId);
        // پرداخت‌کننده باید همان کسی باشد که هزینه برایش صادر می‌شود:
        // در مخاطب «واحدهای خاص» ترجیح با مالک است و اگر نباشد، مستأجر.
        $payerUserId = (int) ($unit['owner_user_id'] ?: $unit['tenant_user_id']);
        if ($payerUserId <= 0) {
            throw new AppException('این واحد مالک یا مستأجری ندارد؛ ابتدا در صفحه واحدها مشخص کنید.');
        }

        // هزینهٔ «دریافت مستقیم» با مبلغ صفر می‌سازیم تا ردیف پرداخت، مرجع حسابداری داشته باشد
        $deposit = new Cost();
        $deposit->building_id = $buildingId;
        $deposit->title = 'پرداخت مستقیم واحد ' . $unit['unit_number'];
        $deposit->description = 'direct-payment';
        $deposit->amount = 0.0;
        $deposit->cost_type = 'direct_deposit';
        $deposit->target_audience = 'specific_units';
        $deposit->division_method = 'fixed_share';
        $deposit->target_unit_ids = [$unitId];
        $deposit->status = 'pending';
        $deposit->created_by = $managerId;
        $deposit->id = $this->costRepo->create($deposit);

        $this->issueCost((int) $deposit->id, $managerId);

        $payment = $this->paymentRepo->findByCostAndUser((int) $deposit->id, $payerUserId);
        if (!$payment) {
            throw new AppException('ردیف پرداخت برای این واحد ساخته نشد.');
        }

        $db = Database::getConnection();
        $now = date('Y-m-d H:i:s');
        $db->prepare(
            "UPDATE cost_payments
             SET amount_paid = ?, status = 'confirmed', payment_date = ?, confirmed_at = ?, confirmed_by = ?, notes = ?
             WHERE id = ?"
        )->execute([$amount, $now, $now, $managerId, $notes, $payment->id]);

        \App\Core\Audit::log($managerId, 'payment.direct', 'cost_payment', (int) $payment->id, $buildingId, [
            'unit_id' => $unitId, 'amount' => $amount,
        ]);
        try {
            (new NotificationService())->createNotification([
                'user_id' => $payerUserId,
                'building_id' => $buildingId,
                'notification_type' => 'payment',
                'title' => 'پرداخت شما ثبت شد',
                'message' => 'پرداخت ' . JalaliHelper::faDigits(number_format($amount)) . ' تومانی توسط مدیر ساختمان برای واحد ' . JalaliHelper::faDigits((string) $unit['unit_number']) . ' ثبت و تأیید شد.',
            ]);
        } catch (\Throwable $e) {
            Logger::error('CostService', 'اعلان پرداخت مستقیم ارسال نشد', ['payment_id' => $payment->id], $e);
        }

        $payment->amount_paid = $amount;
        $payment->status = 'confirmed';
        return $payment;
    }

    /**
     * ثبت مستقیم بدهی (هزینه) برای یک واحد خاص توسط مدیر — بلافاصله صادر می‌شود.
     */
    public function recordUnitCharge(int $buildingId, int $unitId, float $amount, string $title, int $managerId, ?string $dueDate = null): Cost
    {
        $this->requireBuildingManager($managerId, $buildingId);
        if ($amount <= 0) {
            throw new ValidationException('مبلغ بدهی باید بیشتر از صفر باشد.');
        }
        if (trim($title) === '') {
            throw new ValidationException('عنوان بدهی را وارد کنید.');
        }
        $this->resolveUnitInBuilding($buildingId, $unitId);

        $cost = $this->createCost([
            'building_id' => $buildingId,
            'title' => trim($title),
            'amount' => $amount,
            'cost_type' => 'one_time',
            'target_audience' => 'specific_units',
            'target_unit_ids' => [$unitId],
            'division_method' => 'fixed_share',
            'due_date' => $dueDate,
            'description' => 'ثبت مستقیم مدیر برای واحد',
        ], $managerId);

        $this->issueCost((int) $cost->id, $managerId);
        \App\Core\Audit::log($managerId, 'cost.direct', 'cost', (int) $cost->id, $buildingId, [
            'unit_id' => $unitId, 'amount' => $amount,
        ]);
        return $cost;
    }

    // ================================================================
    //  گردش حساب واحد (لجر)
    // ================================================================

    /**
     * گردش حساب واحدهای ساختمان: هر بدهی (سهم صادرشده) و هر اعتبار (پرداخت تأییدشده)
     * به‌ترتیب تاریخ، همراه ماندهٔ لحظه‌ای. ماندهٔ مثبت = طلبکار، منفی = بدهکار.
     *
     * @return array{units: array<int, array<string, mixed>>, totals: array{debt:float, credit:float, balance:float}}
     */
    public function getBuildingLedger(int $buildingId, int $userId): array
    {
        if (!$this->isBuildingMember($userId, $buildingId)) {
            throw new AppException('شما عضو این ساختمان نیستید.');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT cp.id AS payment_id, cp.unit_id, cp.share_amount, cp.amount_paid, cp.status,
                    cp.payment_date, cp.confirmed_at, cp.created_at, cp.notes,
                    c.title AS cost_title, c.cost_type, c.due_date,
                    un.unit_number
             FROM cost_payments cp
             INNER JOIN costs c ON cp.cost_id = c.id
             LEFT JOIN units un ON cp.unit_id = un.id
             WHERE c.building_id = ? AND cp.unit_id IS NOT NULL AND c.deleted_at IS NULL
             ORDER BY cp.unit_id, cp.id"
        );
        $stmt->execute([$buildingId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $unitMeta = [];
        $events = []; // unit_id => list of ['ts','date','title','debit','credit','type']
        foreach ($rows as $r) {
            $uid = (int) $r['unit_id'];
            $unitMeta[$uid] = (string) ($r['unit_number'] ?? $uid);
            $isDeposit = ($r['cost_type'] ?? '') === 'direct_deposit';

            // رویداد بدهی: سهم صادرشده (برای دریافت مستقیم، سهم صفر است)
            $share = (float) ($r['share_amount'] ?? 0);
            if ($share > 0) {
                $events[$uid][] = [
                    'ts' => (string) ($r['created_at'] ?? ''),
                    'title' => $r['cost_title'] ?? 'هزینه',
                    'debit' => $share,
                    'credit' => 0.0,
                    'type' => 'charge',
                ];
            }
            // رویداد اعتبار: پرداخت تأییدشده
            $paid = (float) ($r['amount_paid'] ?? 0);
            if (($r['status'] ?? '') === 'confirmed' && $paid > 0) {
                $events[$uid][] = [
                    'ts' => (string) ($r['confirmed_at'] ?? $r['payment_date'] ?? $r['created_at'] ?? ''),
                    'title' => $isDeposit ? 'پرداخت مستقیم' . ($r['notes'] ? ' — ' . $r['notes'] : '')
                                           : 'پرداخت — ' . ($r['cost_title'] ?? ''),
                    'debit' => 0.0,
                    'credit' => $paid,
                    'type' => 'payment',
                ];
            }
        }

        // تعریف یکتای مانده: همان «مانده بدهکار/طلبکار» مشترک همهٔ صفحه‌ها
        $sharedBalances = [];
        foreach ($this->paymentRepo->unitBalancesByBuilding($buildingId) as $b) {
            $sharedBalances[(int) $b['unit_id']] = $b;
        }

        $units = [];
        $totalDebt = 0.0;
        $totalCredit = 0.0;
        foreach ($events as $uid => $list) {
            usort($list, static fn(array $a, array $b): int => strcmp($a['ts'], $b['ts']) ?: strcmp($a['type'], $b['type']));
            $running = 0.0;
            foreach ($list as &$ev) {
                $running += $ev['credit'] - $ev['debit'];
                $ev['balance'] = round($running, 2);
            }
            unset($ev);

            // ماندهٔ نهایی از سرویس مشترک؛ اگر واحدی آنجا نبود (حالت حاشیه‌ای) از جمع تراکنش‌ها
            $shared = $sharedBalances[$uid] ?? null;
            $final = $shared !== null
                ? (float) $shared['balance']
                : round($running, 2);
            // سازگاری ماندهٔ لحظه‌ایِ آخرین تراکنش با ماندهٔ نهایی
            // (ورود به این حلقه فقط برای واحدهای دارای حداقل یک تراکنش است)
            $list[count($list) - 1]['balance'] = $final;
            if ($final < 0) {
                $totalDebt += -$final;
            } else {
                $totalCredit += $final;
            }
            $units[$uid] = [
                'unit_id' => $uid,
                'unit_number' => $unitMeta[$uid] ?? (string) $uid,
                'total_share' => $shared !== null ? (float) $shared['total_share'] : round($running < 0 ? -$running : 0, 2),
                'total_paid' => $shared !== null ? (float) $shared['total_paid'] : 0.0,
                'balance' => $final,
                'state' => $final < 0 ? 'debtor' : ($final > 0 ? 'creditor' : 'settled'),
                'entries' => $list,
            ];
        }

        return [
            'units' => $units,
            'totals' => [
                'debt' => round($totalDebt, 2),
                'credit' => round($totalCredit, 2),
                'balance' => round($totalCredit - $totalDebt, 2),
            ],
        ];
    }

    /**
     * گزارش ریز مانده‌ها به تفکیک ماه شمسی.
     *
     * برای هر واحد: بدهی صادرشده و پرداخت تأییدشدهٔ هر ماه + ماندهٔ پایان ماه
     * (مانده بدهکار/طلبکار با همان تعریف یکتای لجر). برای کل ساختمان هم جمع ماهانه.
     *
     * @param int|null $unitFilter فقط رویدادهای یک واحد (اختیاری)
     * @return array{units: array, months: array, totals: array}
     */
    public function getMonthlyReport(int $buildingId, int $userId, ?int $unitFilter = null): array
    {
        if (!$this->isBuildingMember($userId, $buildingId)) {
            throw new AppException('شما عضو این ساختمان نیستید.');
        }

        $db = Database::getConnection();
        $sql = "SELECT cp.unit_id, cp.share_amount, cp.amount_paid, cp.status,
                       cp.payment_date, cp.confirmed_at, cp.created_at,
                       c.cost_type, un.unit_number
                FROM cost_payments cp
                INNER JOIN costs c ON cp.cost_id = c.id
                LEFT JOIN units un ON cp.unit_id = un.id
                WHERE c.building_id = ? AND cp.unit_id IS NOT NULL AND c.deleted_at IS NULL";
        $params = [$buildingId];
        if ($unitFilter !== null && $unitFilter > 0) {
            $sql .= ' AND cp.unit_id = ?';
            $params[] = $unitFilter;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $monthKey = static function (?string $ts): ?string {
            $time = $ts !== null && $ts !== '' ? strtotime($ts) : false;
            if ($time === false) {
                return null;
            }
            [$jy, $jm] = \App\Utilities\JalaliHelper::toJalali(
                (int) date('Y', $time),
                (int) date('n', $time),
                (int) date('j', $time)
            );
            return sprintf('%04d-%02d', $jy, $jm);
        };

        // buckets[unitId][jy-jm] = ['charge'=>..,'paid'=>..]
        $buckets = [];
        $unitMeta = [];
        $buildingBuckets = [];
        foreach ($rows as $r) {
            $uid = (int) $r['unit_id'];
            $unitMeta[$uid] = (string) ($r['unit_number'] ?? $uid);

            $share = (float) ($r['share_amount'] ?? 0);
            if ($share > 0) {
                $key = $monthKey((string) ($r['created_at'] ?? ''));
                if ($key !== null) {
                    $buckets[$uid][$key] = $buckets[$uid][$key] ?? ['charge' => 0.0, 'paid' => 0.0];
                    $buildingBuckets[$key] = $buildingBuckets[$key] ?? ['charge' => 0.0, 'paid' => 0.0];
                    $buckets[$uid][$key]['charge'] += $share;
                    $buildingBuckets[$key]['charge'] += $share;
                }
            }
            $paid = (float) ($r['amount_paid'] ?? 0);
            if (($r['status'] ?? '') === 'confirmed' && $paid > 0) {
                $key = $monthKey((string) ($r['confirmed_at'] ?? $r['payment_date'] ?? $r['created_at'] ?? ''));
                if ($key !== null) {
                    $buckets[$uid][$key] = $buckets[$uid][$key] ?? ['charge' => 0.0, 'paid' => 0.0];
                    $buildingBuckets[$key] = $buildingBuckets[$key] ?? ['charge' => 0.0, 'paid' => 0.0];
                    $buckets[$uid][$key]['paid'] += $paid;
                    $buildingBuckets[$key]['paid'] += $paid;
                }
            }
        }

        $formatMonth = static function (string $key): array {
            [$jy, $jm] = array_map('intval', explode('-', $key));
            return [
                'key' => $key,
                'year' => $jy,
                'month' => $jm,
                'label' => \App\Utilities\JalaliHelper::MONTH_NAMES[$jm] . ' ' . \App\Utilities\JalaliHelper::faDigits((string) $jy),
            ];
        };

        $units = [];
        foreach ($buckets as $uid => $months) {
            ksort($months);
            $running = 0.0;
            $list = [];
            foreach ($months as $key => $b) {
                $charge = round((float) $b['charge'], 2);
                $paid = round((float) $b['paid'], 2);
                $running = round($running + $paid - $charge, 2);
                $list[] = $formatMonth($key) + [
                    'charge' => $charge,
                    'paid' => $paid,
                    'balance_end' => $running,
                ];
            }
            $units[] = [
                'unit_id' => (int) $uid,
                'unit_number' => $unitMeta[$uid] ?? (string) $uid,
                'months' => $list,
                'balance_now' => $running,
            ];
        }
        usort($units, static fn(array $a, array $b): int => strnatcmp($a['unit_number'], $b['unit_number']));

        ksort($buildingBuckets);
        $buildingMonths = [];
        $buildingRunning = 0.0;
        foreach ($buildingBuckets as $key => $b) {
            $charge = round((float) $b['charge'], 2);
            $paid = round((float) $b['paid'], 2);
            $buildingRunning = round($buildingRunning + $paid - $charge, 2);
            $buildingMonths[] = $formatMonth($key) + [
                'charge' => $charge,
                'paid' => $paid,
                'net' => round($paid - $charge, 2),
                'balance_end' => $buildingRunning,
            ];
        }

        return [
            'building_id' => $buildingId,
            'units' => $units,
            'months' => $buildingMonths,
            'totals' => [
                'charge' => round(array_sum(array_map(static fn($m) => $m['charge'], $buildingMonths)), 2),
                'paid' => round(array_sum(array_map(static fn($m) => $m['paid'], $buildingMonths)), 2),
                'balance_now' => $buildingRunning,
            ],
        ];
    }

    /**
     * فهرست واحدهای بدهکار برای یادآوری پیامکی.
     *
     * منبع مانده همان تعریف یکتای بدهکار/طلبکار است. واحدهایی که مانده‌شان
     * از آستانهٔ تعیین‌شده منفی‌تر باشد برگردانده می‌شوند همراه مبلغ بدهی
     * و مشخصات پرداخت‌کنندهٔ مسئول (ترجیح با مالک، سپس مستأجر).
     *
     * @param float $minAmount حداقل بدهی برای ورود به فهرست (تومان)
     * @return array<int, array{building_id:int, unit_id:int, unit_number:string, debt:float, user_id:int, name:string, phone:string}>
     */
    public function getDebtorUnits(float $minAmount = 0.0): array
    {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT id FROM buildings WHERE deleted_at IS NULL ORDER BY id');
        $buildings = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];

        $debtors = [];
        foreach ($buildings as $buildingId) {
            $buildingId = (int) $buildingId;
            $buildingName = '';
            $bStmt = $db->prepare('SELECT name FROM buildings WHERE id = ?');
            $bStmt->execute([$buildingId]);
            $buildingName = (string) ($bStmt->fetchColumn() ?: '');

            foreach ($this->paymentRepo->unitBalancesByBuilding($buildingId) as $b) {
                $balance = (float) ($b['balance'] ?? 0);
                if ($balance >= -$minAmount || $balance >= 0.0) {
                    continue; // مانده صفر/طلبکار یا کمتر از آستانه
                }
                $unitId = (int) ($b['unit_id'] ?? 0);

                // مسئول پرداخت: ترجیح با مالک، سپس مستأجر (هم‌راستا با منطق صدور سهم)
                $uStmt = $db->prepare(
                    'SELECT unit_number, owner_user_id, tenant_user_id FROM units WHERE id = ?'
                );
                $uStmt->execute([$unitId]);
                $unit = $uStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
                $payerId = (int) ($unit['owner_user_id'] ?: ($unit['tenant_user_id'] ?? 0));
                if ($payerId <= 0) {
                    continue; // واحد بدون ساکن قابل شناسایی
                }
                $pStmt = $db->prepare('SELECT name, phone FROM users WHERE id = ? AND deleted_at IS NULL');
                $pStmt->execute([$payerId]);
                $payer = $pStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
                $phone = \App\Utilities\PhoneHelper::normalize((string) ($payer['phone'] ?? ''));
                if ($phone === '' || !\App\Utilities\PhoneHelper::isValid($phone)) {
                    continue;
                }

                $debtors[] = [
                    'building_id' => $buildingId,
                    'building_name' => $buildingName,
                    'unit_id' => $unitId,
                    'unit_number' => (string) ($unit['unit_number'] ?? $unitId),
                    'debt' => round(-$balance, 2),
                    'user_id' => $payerId,
                    'name' => (string) ($payer['name'] ?? 'کاربر'),
                    'phone' => $phone,
                ];
            }
        }
        return $debtors;
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
     *   combined    مبلغ ثابت + نفری:  monthly_charge + (تعداد نفرات × نرخ هر نفر)
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
                case 'combined':
                    // ترکیبی: هر واحد مبلغ ثابت + سهم نفرات ساکن را می‌پردازد
                    $amount = (float) $building->monthly_charge
                        + $residents * (float) $building->charge_per_person;
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

        // اگر هنوز واحدی ثبت نشده، در حالت‌های دارای پایهٔ ثابت دست‌کم مبلغ پایه لحاظ می‌شود
        if (empty($units) && in_array($mode, ['fixed', 'combined'], true) && $building->monthly_charge > 0) {
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
                        JalaliHelper::faDigits(number_format($userTotal)) . ' تومان'
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

        if (in_array($cost->division_method, ['custom', 'combined'], true) && is_array($cost->division_details)) {
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
     * @return array<int, array{user_id: int, unit_ids: list<int>, weight: float, units: array<int, float>}> فهرست با کلید = شناسه کاربر
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

    /**
     * ثبت پرداخت توسط ساکن همراه با فیش واریزی «الزامی».
     * رسید در پوشهٔ ساختمان/واحد ذخیره می‌شود و وضعیت مستقیماً به
     * «در انتظار تأیید مدیر» می‌رود (مرحلهٔ جداگانهٔ آپلود حذف شده است).
     */
    public function submitPayment(array $data, int $userId, ?string $receiptContent = null, ?string $receiptName = null): CostPayment
    {
        // ردیف مشخص (پرداخت واحد-محور) یا مسیر قدیمی (هزینه + کاربر)
        $paymentId = (int) ($data['payment_id'] ?? 0);
        $costId = (int) ($data['cost_id'] ?? 0);
        if ($paymentId <= 0 && $costId <= 0) {
            throw new AppException('payment_id or cost_id is required');
        }

        // فیش واریزی برای پرداخت ساکن اجباری است
        if ($receiptContent === null || trim($receiptContent) === '') {
            throw new ValidationException('فیش واریزی الزامی است. لطفاً تصویر رسید پرداخت را پیوست کنید.');
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
        $isPublic = (bool) ($data['receipt_is_public'] ?? false);

        // اگر قبلاً ردیف صادرشده‌ای برای این کاربر وجود دارد، همان به‌روز می‌شود (ردیف تکراری ساخته نمی‌شود)
        if ($existing) {
            $this->paymentRepo->updateSubmission((int) $existing->id, $amountPaid, $notes, 'pending');
            $existing->amount_paid = $amountPaid;
            $existing->notes = $notes;
            $existing->status = 'pending';
            $receiptPath = $this->storeReceiptForPayment((int) $existing->id, $existing, $cost, $receiptContent, $receiptName ?? 'receipt', $isPublic);
            $existing->receipt_path = $receiptPath;
            \App\Core\Audit::log($userId, 'payment.submit', 'payment', (int) $existing->id, $cost->building_id, [
                'cost_id' => $costId, 'amount_paid' => $amountPaid, 'unit_id' => $existing->unit_id,
            ]);
            return $existing;
        }

        $payment = new CostPayment();
        $payment->cost_id = $costId;
        $payment->user_id = $userId;
        $payment->amount_paid = $amountPaid;
        $payment->status = 'pending';
        $payment->receipt_is_public = $isPublic;
        $payment->notes = $notes;

        $id = $this->paymentRepo->create($payment);
        $payment->id = $id;

        $receiptPath = $this->storeReceiptForPayment($id, $payment, $cost, $receiptContent, $receiptName ?? 'receipt', $isPublic);
        $payment->receipt_path = $receiptPath;

        \App\Core\Audit::log($userId, 'payment.submit', 'payment', $id, $cost->building_id, [
            'cost_id' => $costId, 'amount_paid' => $amountPaid, 'unit_id' => $payment->unit_id,
        ]);

        return $payment;
    }

    /**
     * ذخیرهٔ فیش واریزی در «پوشهٔ ساختمان/واحد»، ثبت مسیر روی ردیف پرداخت
     * و نگهداری متادیتا در جدول receipts. وضعیت پرداخت «در انتظار تأیید» می‌شود.
     */
    private function storeReceiptForPayment(int $paymentId, CostPayment $payment, Cost $cost, string $content, string $originalName, bool $isPublic = false): string
    {
        // عنوان پوشهٔ واحد از شمارهٔ واحد ساخته می‌شود
        $unitSlug = '';
        if ($payment->unit_id !== null) {
            $db = Database::getConnection();
            $unitStmt = $db->prepare('SELECT unit_number FROM units WHERE id = ?');
            $unitStmt->execute([$payment->unit_id]);
            $unitSlug = (string) ($unitStmt->fetchColumn() ?: '');
        }

        $path = FileStorage::saveReceipt($content, $cost->building_id, $unitSlug, $paymentId, $originalName);
        if ($path === null) {
            throw new AppException('ذخیرهٔ فیش واریزی ناموفق بود.');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE cost_payments SET receipt_path = ?, receipt_is_public = ?, status = ? WHERE id = ?');
        $stmt->execute([$path, (int) $isPublic, 'pending', $paymentId]);

        // متادیتای رسید (درج یا به‌روزرسانی سازگار با همهٔ درایورها)
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_buffer($finfo, $content);
                finfo_close($finfo);
            }
        }
        $checkStmt = $db->prepare('SELECT id FROM receipts WHERE cost_payment_id = ?');
        $checkStmt->execute([$paymentId]);
        $existingReceiptId = $checkStmt->fetchColumn();
        if ($existingReceiptId) {
            $upStmt = $db->prepare(
                'UPDATE receipts SET file_path = ?, file_size = ?, mime_type = ?, original_name = ?, is_public = ? WHERE id = ?'
            );
            $upStmt->execute([$path, strlen($content), $mime, $originalName, (int) $isPublic, (int) $existingReceiptId]);
        } else {
            $insStmt = $db->prepare(
                'INSERT INTO receipts (cost_payment_id, file_path, file_size, mime_type, original_name, is_public) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insStmt->execute([$paymentId, $path, strlen($content), $mime, $originalName, (int) $isPublic]);
        }

        \App\Core\Audit::log($payment->user_id, 'payment.receipt', 'payment', $paymentId, $cost->building_id, [
            'cost_id' => $payment->cost_id, 'original_name' => $originalName,
        ]);

        return $path;
    }

    /**
     * بارگذاری (یا بارگذاری مجدد پس از ردشدن) فیش واریزی برای یک پرداخت.
     * فایل در همان ساختار «ساختمان/واحد» ذخیره می‌شود و وضعیت به
     * «در انتظار تأیید» بازمی‌گردد.
     */
    public function uploadReceipt(int $paymentId, string $fileContent, string $originalName, int $userId, bool $isPublic = false): ?string
    {
        $payment = $this->getPaymentById($paymentId);
        if (!$payment || $payment->user_id !== $userId) {
            throw new AppException('Payment not found or access denied');
        }
        if ($payment->status === 'confirmed') {
            throw new AppException('پرداخت تأییدشده دیگر قابل تغییر رسید نیست.');
        }

        $cost = $this->costRepo->findById($payment->cost_id);
        if (!$cost) {
            throw new AppException('Cost not found');
        }

        return $this->storeReceiptForPayment($paymentId, $payment, $cost, $fileContent, $originalName, $isPublic);
    }

    public function confirmPayment(int $paymentId, int $managerId): bool
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
            throw new AppException('فقط مدیر ساختمان می‌تواند پرداخت را تأیید کند.');
        }
        if ($payment->status === 'confirmed') {
            throw new AppException('این پرداخت قبلاً تأیید شده است.');
        }

        // اگر مبلغ پرداختی ثبت نشده باشد (مثلاً ساکن فقط رسید آپلود کرده)،
        // هنگام تأیید سهم صادرشده به‌عنوان مبلغ پرداختی لحاظ می‌شود
        if ($payment->amount_paid === null) {
            $fallback = $payment->share_amount;
            if ($fallback === null) {
                $fallback = $cost->amount;
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
                            JalaliHelper::faDigits(number_format((float) ($payment->amount_paid ?? 0))) . ' تومان',
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
     * تأیید گروهی پرداخت‌ها: هر ردیف جداگانه بررسی می‌شود تا یک مورد نامعتبر
     * کل عملیات را متوقف نکند. دسترسی مدیر برای هر پرداخت درون
     * {@see confirmPayment} کنترل می‌شود.
     *
     * @param int[] $paymentIds
     * @return array{processed: int, failed: int, errors: string[]}
     */
    public function bulkConfirmPayments(array $paymentIds, int $managerId): array
    {
        $processed = 0;
        $failed = 0;
        $errors = [];
        foreach ($paymentIds as $paymentId) {
            try {
                if ($this->confirmPayment((int) $paymentId, $managerId)) {
                    $processed++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = 'پرداخت ' . (int) $paymentId . ': ' . $e->getMessage();
            }
        }
        return ['processed' => $processed, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * رد گروهی پرداخت‌ها با دلیل مشترک. مانند تأیید گروهی، خطای هر ردیف
     * جداگانه جمع می‌شود و بقیه ادامه می‌یابند.
     *
     * @param int[] $paymentIds
     * @return array{processed: int, failed: int, errors: string[]}
     */
    public function bulkRejectPayments(array $paymentIds, int $managerId, string $reason): array
    {
        $processed = 0;
        $failed = 0;
        $errors = [];
        foreach ($paymentIds as $paymentId) {
            try {
                if ($this->rejectPayment((int) $paymentId, $managerId, $reason)) {
                    $processed++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = 'پرداخت ' . (int) $paymentId . ': ' . $e->getMessage();
            }
        }
        return ['processed' => $processed, 'failed' => $failed, 'errors' => $errors];
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

    /** دسترسی عمومی به هزینه (برای کنترلرهایی مثل نمایش فیش پرداخت) */
    public function getCostById(int $costId): ?Cost
    {
        return $this->costRepo->findById($costId);
    }

    /** آیا کاربر مدیر فعال این ساختمان است؟ (نسخهٔ عمومی برای کنترلرها) */
    public function isManagerOfBuilding(int $userId, int $buildingId): bool
    {
        return $this->isManager($userId, $buildingId);
    }

    private function mapPaymentRow(array $row): CostPayment
    {
        $p = new CostPayment();
        $p->id = (int) $row['id'];
        $p->cost_id = (int) $row['cost_id'];
        $p->user_id = (int) $row['user_id'];
        $p->unit_id = isset($row['unit_id']) && $row['unit_id'] !== null ? (int) $row['unit_id'] : null;
        $p->amount_paid = $row['amount_paid'] !== null ? (float) $row['amount_paid'] : null;
        $p->share_amount = isset($row['share_amount']) && $row['share_amount'] !== null ? (float) $row['share_amount'] : null;
        $p->status = $row['status'];
        $p->receipt_path = $row['receipt_path'];
        $p->receipt_is_public = (bool) $row['receipt_is_public'];
        $p->notes = $row['notes'];
        $p->reject_reason = isset($row['reject_reason']) ? $row['reject_reason'] : null;
        $p->confirmed_by = $row['confirmed_by'] ? (int) $row['confirmed_by'] : null;
        $p->confirmed_at = $row['confirmed_at'];
        $p->created_at = $row['created_at'];
        return $p;
    }

    public function createPenaltySetting(array $data, int $userId): PenaltySetting
    {
        $setting = new PenaltySetting();
        $setting->building_id = (int) ($data['building_id'] ?? 0);
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
        // جمع بدهی صادرشده و پرداخت‌های تأییدشده از «تعریف یکتای مانده» گرفته می‌شود
        // تا عدد گزارش‌ها با صفحهٔ حسابداری و پروفایل ساختمان یکی باشد.
        $totalDebt = 0.0;
        $totalCollected = 0.0;
        foreach ($this->paymentRepo->unitBalancesByBuilding($buildingId) as $b) {
            $totalDebt += (float) ($b['total_share'] ?? 0);
            $totalCollected += (float) ($b['total_paid'] ?? 0);
        }

        // شمارش هزینه‌های واقعی (بدون حذف‌شده‌ها، قالب دوره‌ای و ردیف‌های دریافت مستقیم)
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM costs
             WHERE building_id = ? AND deleted_at IS NULL
               AND cost_type NOT IN ('direct_deposit', 'recurring')"
        );
        $stmt->execute([$buildingId]);
        $costsCount = (int) $stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN cp.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed
             FROM cost_payments cp
             INNER JOIN costs c ON cp.cost_id = c.id
             WHERE c.building_id = ? AND c.deleted_at IS NULL"
        );
        $stmt->execute([$buildingId]);
        $counts = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $totalDebt = round($totalDebt, 2);
        $totalCollected = round($totalCollected, 2);
        $totalRemaining = round($totalDebt - $totalCollected, 2);
        $collectionPercentage = $totalDebt > 0.0
            ? round(($totalCollected / $totalDebt) * 100, 1)
            : 0.0;

        return [
            'building_id' => $buildingId,
            'total_costs' => $totalDebt,
            'total_collected' => $totalCollected,
            'total_remaining' => $totalRemaining,
            'collection_percentage' => $collectionPercentage,
            'costs_count' => $costsCount,
            'payments_count' => (int) ($counts['total'] ?? 0),
            'confirmed_count' => (int) ($counts['confirmed'] ?? 0),
        ];
    }
}
