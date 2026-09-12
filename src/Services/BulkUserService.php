<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Utilities\PhoneHelper;

/**
 * ساخت/اتصال گروهی کاربران یک ساختمان و انتساب سریع آن‌ها به واحدها.
 *
 * هر ردیف شامل: نام، شماره موبایل، واحد (شماره یا شناسه) و نقش (مالک/مستاجر/ساکن).
 *  - اگر شماره قبلاً ثبت شده باشد، همان کاربر به ساختمان/واحد متصل می‌شود (وضعیت «متصل»).
 *  - رمز عبور اختیاری است؛ در صورت خالی‌بودن، کاربر با کد یک‌بارمصرف وارد می‌شود.
 *  - خطای یک ردیف، بقیهٔ ردیف‌ها را متوقف نمی‌کند.
 */
final class BulkUserService
{
    public const MAX_ROWS = 200;

    private const ROLE_ALIASES = [
        'owner' => 'owner', 'مالک' => 'owner', 'صاحب' => 'owner',
        'tenant' => 'tenant', 'مستاجر' => 'tenant', 'مستأجر' => 'tenant', 'اجاره‌نشین' => 'tenant',
        'resident' => 'resident', 'ساکن' => 'resident',
    ];

    public function __construct(private UserRepository $users = new UserRepository())
    {
    }

    /**
     * @param array<int, array<string, mixed>> $rows ردیف‌های ورودی
     * @param bool $force جایگزینی ساکن فعلی واحدِ پُر
     * @return array{results: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function createBulk(int $buildingId, array $rows, int $managerId, bool $force = false): array
    {
        if ($buildingId <= 0) {
            throw new ValidationException('ساختمان مشخص نیست.');
        }
        if (empty($rows)) {
            throw new ValidationException('هیچ ردیفی برای ثبت وجود ندارد.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new ValidationException('حداکثر ' . self::MAX_ROWS . ' ردیف در هر ثبت گروهی مجاز است.');
        }

        $units = $this->buildingUnits($buildingId);
        $results = [];
        $seenPhones = []; // phone => شماره ردیف ثبت‌شده در همین اجرای گروهی

        foreach (array_values($rows) as $i => $row) {
            $rowNum = $i + 1;
            try {
                $results[] = $this->processRow($rowNum, (array) $row, $buildingId, $managerId, $units, $force, $seenPhones);
            } catch (\Throwable $e) {
                $results[] = [
                    'row' => $rowNum,
                    'status' => 'error',
                    'user_id' => null,
                    'name' => trim((string) ($row['name'] ?? '')),
                    'phone' => (string) ($row['phone'] ?? ''),
                    'unit' => (string) ($row['unit_number'] ?? ($row['unit_id'] ?? '')),
                    'role' => (string) ($row['role'] ?? ''),
                    'message' => $e->getMessage(),
                ];
            }
        }

        $summary = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($results as $r) {
            match ($r['status']) {
                'created' => $summary['created']++,
                'linked' => $summary['linked']++,
                'skipped' => $summary['skipped']++,
                default => $summary['failed']++,
            };
        }

        Audit::log($managerId, 'bulk.create', 'building', $buildingId, $buildingId, [
            'rows' => count($rows), 'created' => $summary['created'],
            'linked' => $summary['linked'], 'skipped' => $summary['skipped'], 'failed' => $summary['failed'],
        ]);

        return ['results' => $results, 'summary' => $summary];
    }

    /**
     * پردازش یک ردیف؛ خروجی رکورد نتیجهٔ همان ردیف است.
     *
     * @param array<int, array{id:int, unit_number:string, owner_user_id:?int, tenant_user_id:?int}> $units
     * @param array<string, int> $seenPhones
     * @return array<string, mixed>
     */
    private function processRow(int $rowNum, array $row, int $buildingId, int $managerId, array $units, bool $force, array &$seenPhones): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        $rawPhone = (string) ($row['phone'] ?? '');
        $unitKey = trim((string) ($row['unit_number'] ?? ''));
        $unitId = (int) ($row['unit_id'] ?? 0);
        $role = $this->normalizeRole((string) ($row['role'] ?? 'resident'));
        $password = trim((string) ($row['password'] ?? ''));

        if ($name === '') {
            throw new ValidationException('نام الزامی است.');
        }
        if (mb_strlen($name) > 100) {
            throw new ValidationException('نام نمی‌تواند بیشتر از ۱۰۰ کاراکتر باشد.');
        }
        $phone = PhoneHelper::normalize($rawPhone);
        if (!PhoneHelper::isValid($phone)) {
            throw new ValidationException('شماره موبایل معتبر نیست. مثال: 09123456789');
        }
        if ($password !== '' && mb_strlen($password) < 6) {
            throw new ValidationException('رمز عبور باید حداقل ۶ کاراکتر باشد.');
        }

        // تکرار شماره در خود لیست گروهی
        if (isset($seenPhones[$phone])) {
            throw new ValidationException('این شماره در ردیف ' . $seenPhones[$phone] . ' همین لیست آمده است.');
        }

        // واحد: با شناسه یا شماره واحد پیدا می‌شود
        $unit = null;
        if ($unitId > 0) {
            foreach ($units as $u) {
                if ((int) $u['id'] === $unitId) {
                    $unit = $u;
                    break;
                }
            }
        } elseif ($unitKey !== '') {
            foreach ($units as $u) {
                if (mb_strtolower(trim((string) $u['unit_number'])) === mb_strtolower($unitKey)) {
                    $unit = $u;
                    break;
                }
            }
        }
        if (($unitId > 0 || $unitKey !== '') && $unit === null) {
            throw new ValidationException('واحد «' . ($unitKey !== '' ? $unitKey : $unitId) . '» در این ساختمان پیدا نشد.');
        }

        // کاربر: یافتن با موبایل یا ساخت کاربر جدید
        $existing = $this->users->findByPhone($phone);
        $createdNow = false;
        if ($existing !== null) {
            $userId = (int) $existing->id;
        } else {
            $user = new User();
            $user->name = $name;
            $user->email = null;
            $user->phone = $phone;
            $user->password_hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
            $userId = (int) $this->users->create($user);
            $createdNow = true;
        }

        // عضویت ساختمان (بدون خطا در تکرار) — سینتکس سازگار با هر دو موتور
        $db = Database::getConnection();
        $stmt = Database::prepareInsertIgnore(
            $db,
            "INSERT IGNORE INTO building_members (user_id, building_id, role, status, invited_by)
             VALUES (?, ?, ?, 'active', ?)"
        );
        $stmt->execute([$userId, $buildingId, $role, $managerId]);

        // انتساب به واحد (مالک/مستاجر)
        $unitLabel = null;
        $assignNote = '';
        if ($unit !== null && in_array($role, ['owner', 'tenant'], true)) {
            $column = $role === 'owner' ? 'owner_user_id' : 'tenant_user_id';
            $current = (int) ($unit[$column] ?? 0);
            if ($current > 0 && $current !== $userId && !$force) {
                // کاربر ساخته/عضو شده ولی واحد ساکن دارد؛ بدون جایگزینی رد می‌شود
                return [
                    'row' => $rowNum,
                    'status' => 'skipped',
                    'user_id' => $userId,
                    'name' => $name,
                    'phone' => $phone,
                    'unit' => $unit['unit_number'],
                    'role' => $role,
                    'message' => 'عضو ساختمان شد ولی واحد «' . $unit['unit_number'] . '» از قبل ساکن دارد؛ برای جایگزینی گزینهٔ «جایگزینی» را فعال کنید.',
                ];
            }
            $db->prepare("UPDATE units SET {$column} = ? WHERE id = ?")->execute([$userId, (int) $unit['id']]);
            if ($role === 'owner' && $unit['tenant_user_id'] === null) {
                $db->prepare("UPDATE units SET owner_resident = 1 WHERE id = ?")->execute([(int) $unit['id']]);
            } elseif ($role === 'tenant') {
                $db->prepare("UPDATE units SET owner_resident = 0 WHERE id = ?")->execute([(int) $unit['id']]);
            }
            $unitLabel = $unit['unit_number'];
        } elseif ($unit !== null) {
            $assignNote = ' (نقش ساکن به واحد متصل نمی‌شود)';
            $unitLabel = $unit['unit_number'];
        }

        $seenPhones[$phone] = $rowNum;

        // اعلان درون‌برنامه‌ای برای کاربر تازه‌اضافه‌شده (شکست اعلان، کار را خراب نمی‌کند)
        try {
            (new NotificationService())->createNotification([
                'user_id' => $userId,
                'building_id' => $buildingId,
                'notification_type' => 'general',
                'title' => 'به ساختمان اضافه شدید',
                'message' => 'حساب شما به‌عنوان ' . $this->roleLabel($role) . ' به ساختمان اضافه شد.',
            ]);
        } catch (\Throwable $e) {
            Logger::error('BulkUserService', 'اعلان عضویت ارسال نشد', ['user_id' => $userId], $e);
        }

        $message = ($createdNow ? 'کاربر ساخته شد' : 'کاربر از قبل وجود داشت، متصل شد') . $assignNote;
        if ($password === '' && $createdNow) {
            $message .= ' — ورود با کد یک‌بارمصرف';
        }

        return [
            'row' => $rowNum,
            'status' => $createdNow ? 'created' : 'linked',
            'user_id' => $userId,
            'name' => $name,
            'phone' => $phone,
            'unit' => $unitLabel,
            'role' => $role,
            'message' => $message,
        ];
    }

    /** @return array<int, array{id:int, unit_number:string, owner_user_id:?int, tenant_user_id:?int}> */
    private function buildingUnits(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id, unit_number, owner_user_id, tenant_user_id
             FROM units WHERE building_id = ? ORDER BY unit_number"
        );
        $stmt->execute([$buildingId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'unit_number' => (string) $r['unit_number'],
            'owner_user_id' => $r['owner_user_id'] !== null ? (int) $r['owner_user_id'] : null,
            'tenant_user_id' => $r['tenant_user_id'] !== null ? (int) $r['tenant_user_id'] : null,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function normalizeRole(string $role): string
    {
        $role = trim($role);
        if ($role === '') {
            return 'resident';
        }
        return self::ROLE_ALIASES[$role] ?? self::ROLE_ALIASES[mb_strtolower($role)] ?? (
            in_array($role, ['owner', 'tenant', 'resident'], true) ? $role : 'resident'
        );
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'owner' => 'مالک',
            'tenant' => 'مستاجر',
            default => 'ساکن',
        };
    }
}
