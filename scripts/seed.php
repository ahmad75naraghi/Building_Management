<?php
/**
 * ==================== ساخت دادهٔ نمونه (ساختمان واقعی) ====================
 * ساختمان واقعی شما را با همهٔ واحدها، ساکنین، شارژ و پرداخت‌ها می‌سازد؛
 * مناسب دمو، تست روی سرور واقعی و مشاهدهٔ نمودارهای داشبورد.
 *
 * اجرا:
 *   php scripts/seed.php            ساخت ساختمان
 *   php scripts/seed.php --force    حذف نمونهٔ قبلی و ساخت مجدد
 *
 * ⚠️ مشخصات ساکنین (نام و موبایل) را در آرایهٔ $people پایین وارد کنید؛
 *    تا وقتی پر نشود، نام‌های جاگیر موقت با شماره‌های تستی ساخته می‌شود.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && getenv('BMS_SEED_FORCE') !== '1') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Services\BuildingService;
use App\Services\CostService;
use App\Services\NotificationService;
use App\Services\UnitService;
use App\Services\UserService;

/* ============================================================
 *  مشخصات ساختمان (واقعی)
 * ============================================================ */
$seed = [
    'building' => [
        'name' => 'ساختمان مسکونی',          // ← نام ساختمان خود را بنویسید
        'address' => 'آدرس ساختمان را اینجا وارد کنید', // ← آدرس واقعی
        // شارژ ترکیبی: ۳۵۰ هزار تومان ثابت هر واحد + ۵۰ هزار تومان هر نفر
        'monthly_charge' => 350000,
        'charge_mode' => 'combined',
        'charge_per_person' => 50000,
    ],
    'manager' => ['name' => 'مدیر ساختمان', 'phone' => '09120000001'], // ← مدیر (خودتان)
    // ساختار: دو بلوک شرقی و غربی، هر کدام ۵ طبقه و هر طبقه ۴ واحد (۴۰ واحد)
    'blocks' => ['شرقی', 'غربی'],
    'floors_per_block' => 5,
    'units_per_floor' => 4,
    'residents_per_unit' => 3,   // تعداد پیش‌فرض سکنه هر واحد (قابل ویرایش واحد‌به‌واحد در $people)
    // هزینه‌های موردی نمونه
    'one_time_costs' => [
        ['title' => 'سرویس و تعمیر آسانسور', 'amount' => 2400000],
    ],
];

/* ============================================================
 *  مشخصات ساکنین — دادهٔ واقعی را اینجا وارد کنید
 *  کلید: «نام‌بلوک-شماره‌واحد» مثل «شرقی-101»
 *  هر واحد: نام/موبایل مالک، و در صورت وجود مستاجر.
 *  نمونه:
 *    'شرقی-101' => ['residents' => 3,
 *                   'owner'  => ['name' => 'علی محمدی',  'phone' => '09121111111'],
 *                   'tenant' => null],
 *    'غربی-203' => ['residents' => 2,
 *                   'owner'  => ['name' => 'رضا کریمی', 'phone' => '09122222222'],
 *                   'tenant' => ['name' => 'سارا رضایی', 'phone' => '09123333333']],
 * ============================================================ */
$people = [
    // 'شرقی-101' => ['residents' => 3, 'owner' => ['name' => '...', 'phone' => '09...'], 'tenant' => null],
];
/* ============================================================ */

$force = in_array('--force', $argv ?? [], true);
$db = Database::getConnection();

$buildingName = trim((string) $seed['building']['name']);

// ---------------- محافظت در برابر اجرای تکراری ----------------
$check = $db->prepare('SELECT id FROM buildings WHERE name = ? AND deleted_at IS NULL LIMIT 1');
$check->execute([$buildingName]);
$existing = $check->fetchColumn();
if ($existing) {
    if (!$force) {
        echo "ساختمان «{$buildingName}» از قبل وجود دارد. برای ساخت مجدد: --force\n";
        exit(1);
    }
    echo "حذف نمونهٔ قبلی…\n";
    $db->prepare(
        'DELETE FROM cost_payments WHERE cost_id IN (SELECT id FROM costs WHERE building_id = ?)'
    )->execute([$existing]);
    $db->prepare('DELETE FROM costs WHERE building_id = ?')->execute([$existing]);
    $db->prepare('DELETE FROM building_members WHERE building_id = ?')->execute([$existing]);
    foreach (['units', 'floors', 'blocks'] as $t) {
        $db->prepare("DELETE FROM {$t} WHERE building_id = ?")->execute([$existing]);
    }
    $db->prepare('DELETE FROM buildings WHERE id = ?')->execute([$existing]);
}

// ---------------- ساخت کاربران ----------------
$users = new UserService();
$buildingService = new BuildingService();
$unitService = new UnitService();

function seed_user(UserService $users, array $person): int
{
    try {
        $user = $users->register([
            'name' => $person['name'],
            'phone' => $person['phone'],
            'password' => 'seed-' . random_int(100000, 999999),
        ]);
        return (int) $user->id;
    } catch (\Throwable $e) {
        // شماره از قبل وجود دارد → همان کاربر را پیدا کن
        $phone = \App\Utilities\PhoneHelper::normalize($person['phone']);
        $existing = (new \App\Repositories\UserRepository())->findByPhone($phone);
        if ($existing) {
            return (int) $existing->id;
        }
        throw $e;
    }
}

echo "ساخت کاربران…\n";
$managerId = seed_user($users, $seed['manager']);

// ---------------- ساخت ساختمان ----------------
echo "ساخت ساختمان «{$buildingName}»…\n";
$building = $buildingService->createBuilding([
    'name' => $seed['building']['name'],
    'address' => $seed['building']['address'],
    'monthly_charge' => $seed['building']['monthly_charge'],
    'monthly_charge_enabled' => true,
    'charge_mode' => $seed['building']['charge_mode'],
    'charge_per_person' => $seed['building']['charge_per_person'],
    'has_blocks' => true,
    'total_floors' => null,   // ساختار را خودمان دقیق می‌سازیم (بدون ساخت خودکار)
    'total_units' => null,
], $managerId);
$buildingId = (int) $building->id;

// ---------------- بلوک‌ها، طبقات و واحدها ----------------
$unitIds = [];   // «نام‌بلوک-شماره‌واحد» => شماره واحد در جدول
$placeholderSeq = 1;

foreach ($seed['blocks'] as $blockName) {
    $db->prepare('INSERT INTO blocks (building_id, name) VALUES (?, ?)')
        ->execute([$buildingId, 'بلوک ' . $blockName]);
    $blockId = (int) $db->lastInsertId();

    $floorIds = [];
    for ($floor = 1; $floor <= $seed['floors_per_block']; $floor++) {
        $db->prepare('INSERT INTO floors (building_id, block_id, floor_number) VALUES (?, ?, ?)')
            ->execute([$buildingId, $blockId, $floor]);
        $floorIds[$floor] = (int) $db->lastInsertId();
    }

    for ($floor = 1; $floor <= $seed['floors_per_block']; $floor++) {
        for ($n = 1; $n <= $seed['units_per_floor']; $n++) {
            $unitNumber = (string) ($floor * 100 + $n); // ۱۰۱ تا ۵۰۴
            $key = $blockName . '-' . $unitNumber;

            // مشخصات ساکنین: واقعی اگر وارد شده باشد، وگرنه جاگیر موقت
            $info = $people[$key] ?? null;
            if ($info && !empty($info['owner']['phone'])) {
                $ownerPerson = $info['owner'];
                $tenantPerson = !empty($info['tenant']['phone']) ? $info['tenant'] : null;
                $residents = (int) ($info['residents'] ?? $seed['residents_per_unit']);
            } else {
                $ownerPerson = [
                    'name' => "مالک واحد {$unitNumber} ({$blockName})",
                    'phone' => sprintf('09129%06d', $placeholderSeq++),
                ];
                $tenantPerson = null;
                $residents = (int) $seed['residents_per_unit'];
            }

            $ownerId = seed_user($users, $ownerPerson);
            $tenantId = $tenantPerson !== null ? seed_user($users, $tenantPerson) : null;
            foreach ([$ownerId, $tenantId] as $memberId) {
                if ($memberId === null) {
                    continue;
                }
                $mstmt = Database::prepareInsertIgnore(
                    $db,
                    'INSERT IGNORE INTO building_members (user_id, building_id, role, status, invited_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $mstmt->execute([$memberId, $buildingId, $memberId === $tenantId ? 'tenant' : 'owner', 'active', $managerId]);
            }

            $unit = $unitService->createUnit([
                'unit_number' => $unitNumber,
                'block_id' => $blockId,
                'floor_id' => $floorIds[$floor],
                'area' => 90,
                'owner_user_id' => $ownerId,
                'tenant_user_id' => $tenantId,
                'owner_resident' => $tenantId === null,
                'residents_count' => $residents,
            ], $buildingId, $managerId);
            $unitIds[$key] = (int) $unit->id;
        }
    }
}

// ---------------- شارژ ماهانه و هزینه‌های موردی ----------------
echo "صدور شارژ و هزینه‌ها…\n";
$costs = new CostService();
$costs->generateMonthlyChargeForBuilding($buildingId);

foreach ($seed['one_time_costs'] as $c) {
    $cost = $costs->createCost([
        'building_id' => $buildingId,
        'title' => $c['title'],
        'amount' => $c['amount'],
        'cost_type' => 'one_time',
        'target_audience' => 'all',
        'division_method' => 'fixed_share',
        'due_date' => date('Y-m-d', strtotime('+10 days')),
    ], $managerId);
    // صدور هزینه تا سهم هر واحدها ساخته شود
    $costs->issueCost((int) $cost->id, $managerId);
}

// ---------------- پرداخت‌های نمونه ----------------
// الگو: از هر ۴ واحد، ۳ واحد پرداخت کامل دارند و ۱ واحد بدهکار می‌ماند؛
// چند واحد هم پرداخت ناقص دارند تا نمودارها واقع‌گرایانه باشند.
echo "ثبت پرداخت‌ها…\n";
$idx = 0;
foreach ($unitIds as $key => $unitId) {
    $idx++;
    $stmt = $db->prepare('SELECT COALESCE(SUM(share_amount),0) FROM cost_payments WHERE unit_id = ? AND status = ?');
    $stmt->execute([$unitId, 'pending']);
    $due = (float) $stmt->fetchColumn();
    if ($due <= 0) {
        continue;
    }
    if ($idx % 4 === 0) {
        continue; // این واحد بدهکار می‌ماند
    }
    $amount = ($idx % 9 === 0) ? $due * 0.5 : $due; // هر چند واحد یک‌بار پرداخت ناقص
    $costs->recordDirectPayment($buildingId, $unitId, $amount, 'پرداخت نمونه', $managerId);
}

// ---------------- اطلاعیه و اعلان خوش‌آمدگویی ----------------
$notifications = new NotificationService();
$notifications->createNotification([
    'user_id' => $managerId,
    'building_id' => $buildingId,
    'notification_type' => 'general',
    'title' => 'دادهٔ ساختمان ساخته شد',
    'message' => 'ساختمان با واحدها، شارژ و پرداخت‌های تستی آماده است.',
]);
$db->prepare('INSERT INTO announcements (building_id, title, content, is_pinned, created_by) VALUES (?, ?, ?, 1, ?)')
    ->execute([$buildingId, 'به ساختمان خوش آمدید 🏠', 'این یک اطلاعیهٔ نمونه است؛ می‌توانید آن را ویرایش یا حذف کنید.', $managerId]);

echo "\n✅ ساختمان «{$buildingName}» با موفقیت ساخته شد.\n";
echo '   واحدها: ' . count($unitIds) . ' | بلوک‌ها: ' . implode(' و ', $seed['blocks']) . "\n";
echo '   شارژ: ' . number_format($seed['building']['monthly_charge']) . ' تومان ثابت + '
    . number_format($seed['building']['charge_per_person']) . " تومان هر نفر\n";
echo "   ورود همهٔ کاربران با شماره موبایل + کد یک‌بارمصرف است.\n";
