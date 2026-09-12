<?php
/**
 * ==================== ساخت دادهٔ نمونه ====================
 * یک ساختمان کامل (مدیر، ساکنین، واحدها، شارژ، هزینه و پرداخت) می‌سازد؛
 * مناسب دمو، تست روی سرور واقعی و مشاهدهٔ نمودارهای داشبورد.
 *
 * اجرا:
 *   php scripts/seed.php            ساخت ساختمان نمونه
 *   php scripts/seed.php --force    حذف نمونهٔ قبلی و ساخت مجدد
 *
 * ⚠️ مشخصات داخل آرایهٔ $seed پایین را با «دادهٔ واقعی ساختمان خودتان»
 *    جایگزین کنید؛ بقیهٔ اسکریپت همه‌چیز را از روی همان می‌سازد.
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
 *  دادهٔ نمونه — مشخصات ساختمان خودتان را اینجا وارد کنید
 * ============================================================ */
$seed = [
    'building' => [
        'name' => 'ساختمان نمونه',
        'address' => 'تهران، خیابان نمونه، کوچه نمونه، پلاک ۱',
        'monthly_charge' => 500000,   // شارژ ثابت ماهانه هر واحد (تومان)
        'charge_mode' => 'fixed',     // fixed | per_person | custom
        'charge_per_person' => 0,
    ],
    // مدیر ساختمان (خودتان)
    'manager' => ['name' => 'مدیر ساختمان', 'phone' => '09120000001'],
    // بلوک‌ها، طبقات و واحدها — هر واحد: شماره، طبقه، متراژ، تعداد سکنه،
    // مالک و مستاجر (نام + موبایل؛ اگر واحدی مستاجر ندارد فقط مالک).
    'blocks' => [
        [
            'name' => 'بلوک A',
            'units' => [
                ['number' => '1', 'floor' => 1, 'area' => 85,  'residents' => 2,
                 'owner' => ['name' => 'علی محمدی', 'phone' => '09120000011'], 'tenant' => null],
                ['number' => '2', 'floor' => 1, 'area' => 90,  'residents' => 3,
                 'owner' => ['name' => 'رضا کریمی', 'phone' => '09120000012'], 'tenant' => null],
                ['number' => '3', 'floor' => 2, 'area' => 85,  'residents' => 2,
                 'owner' => ['name' => 'حسین احمدی', 'phone' => '09120000013'],
                 'tenant' => ['name' => 'سارا رضایی', 'phone' => '09120000014']],
                ['number' => '4', 'floor' => 2, 'area' => 95,  'residents' => 4,
                 'owner' => ['name' => 'مریم حسینی', 'phone' => '09120000015'], 'tenant' => null],
                ['number' => '5', 'floor' => 3, 'area' => 100, 'residents' => 3,
                 'owner' => ['name' => 'امیر تهرانی', 'phone' => '09120000016'],
                 'tenant' => ['name' => 'نگار موسوی', 'phone' => '09120000017']],
                ['number' => '6', 'floor' => 3, 'area' => 100, 'residents' => 2,
                 'owner' => ['name' => 'فاطمه نوری', 'phone' => '09120000018'], 'tenant' => null],
            ],
        ],
    ],
    // چند پرداخت مستقیم نمونه (واحد ← مبلغ) — بقیه واحدها بدهکار می‌مانند
    'payments' => [
        ['unit' => '1', 'amount' => 500000],
        ['unit' => '2', 'amount' => 500000],
        ['unit' => '4', 'amount' => 300000], // پرداخت ناقص
    ],
    // هزینه‌های موردی نمونه
    'one_time_costs' => [
        ['title' => 'تعمیر آسانسور', 'amount' => 1200000],
        ['title' => 'شارژ اضافی نظافت مشاعات', 'amount' => 300000],
    ],
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
    'monthly_charge_enabled' => $seed['building']['monthly_charge'] > 0,
    'charge_mode' => $seed['building']['charge_mode'],
    'charge_per_person' => $seed['building']['charge_per_person'],
    'has_blocks' => count($seed['blocks']) > 1 || ($seed['blocks'][0]['name'] ?? '') !== '',
    'total_floors' => null,
    'total_units' => null,
], $managerId);
$buildingId = (int) $building->id;

// ---------------- اعضا، طبقات و واحدها ----------------
$unitIds = [];
foreach ($seed['blocks'] as $blockSpec) {
    $db->prepare('INSERT INTO blocks (building_id, name) VALUES (?, ?)')
        ->execute([$buildingId, $blockSpec['name'] ?? 'بلوک ۱']);
    $blockId = (int) $db->lastInsertId();

    // طبقات این بلوک
    $floorIds = [];
    $floorsNeeded = array_unique(array_map(static fn($u) => (int) $u['floor'], $blockSpec['units']));
    sort($floorsNeeded);
    foreach ($floorsNeeded as $fn) {
        $db->prepare('INSERT INTO floors (building_id, block_id, floor_number) VALUES (?, ?, ?)')
            ->execute([$buildingId, $blockId, $fn]);
        $floorIds[$fn] = (int) $db->lastInsertId();
    }

    foreach ($blockSpec['units'] as $u) {
        // ساخت/یافتن مالک و مستاجر و عضویت آن‌ها
        $ownerId = $u['owner'] ? seed_user($users, $u['owner']) : null;
        $tenantId = $u['tenant'] ? seed_user($users, $u['tenant']) : null;
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
            'unit_number' => $u['number'],
            'block_id' => $blockId,
            'floor_id' => $floorIds[(int) $u['floor']] ?? null,
            'area' => $u['area'] ?? null,
            'owner_user_id' => $ownerId,
            'tenant_user_id' => $tenantId,
            'owner_resident' => $tenantId === null,
            'residents_count' => $u['residents'] ?? 1,
        ], $buildingId, $managerId);
        $unitIds[(string) $u['number']] = (int) $unit->id;
    }
}

// ---------------- شارژ ماهانه و هزینه‌های موردی ----------------
echo "صدور شارژ و هزینه‌ها…\n";
$costs = new CostService();
$costs->generateMonthlyChargeForBuilding($buildingId);

foreach ($seed['one_time_costs'] as $c) {
    $costs->createCost([
        'building_id' => $buildingId,
        'title' => $c['title'],
        'amount' => $c['amount'],
        'target_audience' => 'all',
        'division_method' => 'fixed_share',
        'due_date' => date('Y-m-d', strtotime('+10 days')),
    ], $managerId);
}

// ---------------- پرداخت‌های نمونه ----------------
foreach ($seed['payments'] as $p) {
    if (isset($unitIds[(string) $p['unit']])) {
        $costs->recordDirectPayment($buildingId, $unitIds[(string) $p['unit']], (float) $p['amount'], 'پرداخت نمونه', $managerId);
    }
}

// ---------------- یک اعلان و اطلاعیهٔ خوش‌آمدگویی ----------------
$notifications = new NotificationService();
$notifications->createNotification([
    'user_id' => $managerId,
    'building_id' => $buildingId,
    'notification_type' => 'general',
    'title' => 'دادهٔ نمونه ساخته شد',
    'message' => 'ساختمان نمونه با واحدها، شارژ و پرداخت‌های تستی آماده است.',
]);
$db->prepare('INSERT INTO announcements (building_id, title, content, is_pinned, created_by) VALUES (?, ?, ?, 1, ?)')
    ->execute([$buildingId, 'به ساختمان خوش آمدید 🏠', 'این یک اطلاعیهٔ نمونه است؛ می‌توانید آن را ویرایش یا حذف کنید.', $managerId]);

echo "\n✅ ساختمان «{$buildingName}» با موفقیت ساخته شد.\n";
echo '   واحدها: ' . count($unitIds) . ' | مدیر: ' . $seed['manager']['name'] . ' (' . $seed['manager']['phone'] . ")\n";
echo "   ورود همهٔ کاربران با شماره موبایل + کد یک‌بارمصرف است.\n";
