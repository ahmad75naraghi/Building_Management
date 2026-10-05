<?php
/**
 * ساخت دادهٔ نمونه برای ساختمان — برای آشنایی کاربران تازه‌وارد.
 *
 * واحدها (در صورت خالی‌بودن از مشخصات ساختمان)، چند ساکن نمونه، هزینهٔ صادرشده،
 * پرداخت تأییدشده، اعلان و تیکت نمونه می‌سازد. ایدمپوتنت است: اگر ساکن نمونه
 * از قبل عضو ساختمان باشد، کاری نمی‌کند.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Exceptions\AppException;

final class DemoSeederService
{
    /** ساکنین نمونه — شماره‌های آزمایشی که هیچ‌وقت پیامک واقعی نمی‌گیرند */
    private const DEMO_PEOPLE = [
        ['name' => 'علی محمدی', 'phone' => '09301100101', 'role' => 'owner', 'unit' => 0],
        ['name' => 'مریم حسینی', 'phone' => '09301100102', 'role' => 'owner', 'unit' => 1],
        ['name' => 'رضا کریمی', 'phone' => '09301100103', 'role' => 'tenant', 'unit' => 2],
        ['name' => 'زهرا احمدی', 'phone' => '09301100104', 'role' => 'owner', 'unit' => 3],
    ];

    public function __construct(
        private BuildingService $buildings = new BuildingService(),
        private UserService $users = new UserService(),
        private CostService $costs = new CostService(),
        private TicketService $tickets = new TicketService(),
        private ExtraModulesService $extra = new ExtraModulesService(),
    ) {
    }

    /**
     * @return array{seeded: bool, message: string, summary?: array<string,int>}
     */
    public function seed(int $buildingId, int $managerId): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare('SELECT COUNT(*) FROM units WHERE building_id = ?');
        $stmt->execute([$buildingId]);
        if ((int) $stmt->fetchColumn() === 0) {
            // از مشخصات ساختمان بساز؛ اگر مشخصاتی نبود، پیش‌فرض کوچک
            $created = $this->buildings->scaffoldFromSpecs($buildingId);
            if ($created === 0) {
                $this->createDefaultStructure($buildingId);
            }
        }

        // واحدهای ساختمان (برای انتساب ساکنین)
        $stmt = $db->prepare('SELECT id FROM units WHERE building_id = ? ORDER BY id ASC');
        $stmt->execute([$buildingId]);
        $unitIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if (!$unitIds) {
            throw new AppException('ساختمان واحدی ندارد؛ ابتدا ساختار را بسازید.');
        }

        // ایدمپوتنت: اگر ساکن نمونه از قبل عضو این ساختمان است، رد شو
        $firstPhone = self::DEMO_PEOPLE[0]['phone'];
        $existing = $this->users->findByPhonePublic($firstPhone);
        if ($existing !== null) {
            $check = $db->prepare(
                "SELECT COUNT(*) FROM building_members WHERE user_id = ? AND building_id = ? AND status = 'active'"
            );
            $check->execute([$existing->id, $buildingId]);
            if ((int) $check->fetchColumn() > 0) {
                return ['seeded' => false, 'message' => 'دادهٔ نمونه قبلاً برای این ساختمان ساخته شده است.'];
            }
        }

        $summary = ['users' => 0, 'members' => 0, 'costs' => 0, 'payments' => 0];

        // ۱) ساخت/عضویت ساکنین نمونه و انتساب به واحدها
        $demoUserIds = [];
        foreach (self::DEMO_PEOPLE as $person) {
            $unitId = $unitIds[$person['unit'] % count($unitIds)];
            $user = $this->users->findByPhonePublic($person['phone']);
            if ($user === null) {
                try {
                    $user = $this->users->register([
                        'name' => $person['name'],
                        'phone' => $person['phone'],
                        'password' => 'demo-' . $person['phone'],
                    ]);
                    $summary['users']++;
                } catch (\Throwable $e) {
                    continue; // کاربر نمونه ساخته نشد → بدون توقف ادامه بده
                }
            }

            $ins = Database::prepareInsertIgnore(
                $db,
                "INSERT IGNORE INTO building_members (user_id, building_id, role, status, invited_by)
                 VALUES (?, ?, ?, 'active', ?)"
            );
            $ins->execute([(int) $user->id, $buildingId, $person['role'], $managerId]);
            $summary['members']++;
            $demoUserIds[] = (int) $user->id;

            $column = $person['role'] === 'owner' ? 'owner_user_id' : 'tenant_user_id';
            $db->prepare("UPDATE units SET {$column} = ? WHERE id = ? AND {$column} IS NULL")
                ->execute([(int) $user->id, $unitId]);
        }

        // ۲) دو هزینهٔ نمونه — صادرشده برای همهٔ واحدها
        $costDefs = [
            ['title' => 'شارژ ماهانه (نمونه)', 'amount' => 350000, 'type' => 'periodic'],
            ['title' => 'هزینهٔ نظافت مشاعات (نمونه)', 'amount' => 800000, 'type' => 'one_time'],
        ];
        $issuedCostIds = [];
        foreach ($costDefs as $def) {
            try {
                $cost = $this->costs->createCost([
                    'building_id' => $buildingId,
                    'title' => $def['title'],
                    'description' => 'دادهٔ نمونه — برای آشنایی با سامانه؛ می‌توانید حذفش کنید.',
                    'amount' => $def['amount'],
                    'cost_type' => $def['type'],
                    'target_audience' => 'all',
                    'division_method' => 'fixed_share',
                ], $managerId);
                $this->costs->issueCost((int) $cost->id, $managerId);
                $issuedCostIds[] = (int) $cost->id;
                $summary['costs']++;
            } catch (\Throwable $e) {
                continue;
            }
        }

        // ۳) دو پرداخت نمونهٔ تأییدشده (روی اولین هزینه)
        if ($issuedCostIds !== []) {
            $paidUnits = array_slice($unitIds, 0, 2);
            foreach ($paidUnits as $unitId) {
                try {
                    $this->costs->recordDirectPayment($buildingId, $unitId, 350000, 'پرداخت نمونه', $managerId);
                    $summary['payments']++;
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        // ۴) یک اعلان و یک تیکت نمونه
        try {
            $this->extra->createAnnouncement([
                'building_id' => $buildingId,
                'title' => 'به سامانه خوش آمدید 🎉',
                'content' => 'این یک اعلان نمونه است. از بخش اعلان‌ها می‌توانید آن را ویرایش یا حذف کنید.',
            ], $managerId);
        } catch (\Throwable $e) {
            // غیرحیاتی
        }
        try {
            $this->tickets->createTicket([
                'building_id' => $buildingId,
                'title' => 'نمونه: درخواست تعمیر درب پارکینگ',
                'description' => 'این یک تیکت نمونه است تا جریان ثبت و پاسخ‌دادن تیکت را ببینید.',
                'category' => 'technical',
                'priority' => 'normal',
            ], $demoUserIds[0] ?? $managerId);
        } catch (\Throwable $e) {
            // غیرحیاتی
        }

        \App\Core\Audit::log($managerId, 'building.demo_seed', 'building', $buildingId, $buildingId, $summary);

        return [
            'seeded' => true,
            'message' => 'دادهٔ نمونه ساخته شد: ' . $summary['members'] . ' ساکن، '
                . $summary['costs'] . ' هزینه، ' . $summary['payments'] . ' پرداخت تأییدشده.',
            'summary' => $summary,
        ];
    }

    /** ساختار پیش‌فرض کوچک برای ساختمانی که مشخصات طبقه/واحد ندارد */
    private function createDefaultStructure(int $buildingId): void
    {
        $db = Database::getConnection();
        $db->prepare('INSERT INTO floors (building_id, floor_number, name) VALUES (?, ?, ?)')
            ->execute([$buildingId, 1, 'طبقه 1']);
        $floorId = (int) $db->lastInsertId();
        $ins = $db->prepare(
            "INSERT INTO units (building_id, floor_id, unit_number, type, occupancy_status)
             VALUES (?, ?, ?, 'residential', 'no_owner')"
        );
        foreach (['101', '102', '103', '104'] as $number) {
            $ins->execute([$buildingId, $floorId, $number]);
        }
    }
}
