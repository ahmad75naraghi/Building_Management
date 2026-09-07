<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Models\Building;
use App\Repositories\BuildingRepository;
use App\Repositories\BuildingHierarchyRepository;
use App\Utilities\Validator;

final class BuildingService
{
    public function __construct(
        private BuildingRepository $repo = new BuildingRepository(),
        private BuildingHierarchyRepository $hierarchyRepo = new BuildingHierarchyRepository(),
    ) {
    }

    public function createBuilding(array $data, int $userId): Building
    {
        $errors = Validator::validate($data, [
            'name' => 'required',
            'address' => 'required',
        ]);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $building = new Building();
        $building->name = $data['name'];
        $building->address = $data['address'];
        $building->created_by = $userId;
        $building->custom_name = $data['custom_name'] ?? $data['name'];
        $building->theme_color = $data['theme_color'] ?? '#1a73e8';
        $building->hierarchy_settings = $data['hierarchy_settings'] ?? [
            'has_blocks' => true,
            'has_floors' => true,
            'has_units' => true,
            'has_common_areas' => true,
        ];
        $building->total_units = isset($data['total_units']) && $data['total_units'] !== '' ? (int) $data['total_units'] : null;
        $building->total_floors = isset($data['total_floors']) && $data['total_floors'] !== '' ? (int) $data['total_floors'] : null;
        $building->has_blocks = isset($data['has_blocks']) ? (bool) $data['has_blocks'] : true;
        $building->default_image = $data['default_image'] ?? 'b1';
        $building->parking_spots = isset($data['parking_spots']) ? max(0, (int) $data['parking_spots']) : 0;
        $building->monthly_charge = isset($data['monthly_charge']) ? max(0, (float) $data['monthly_charge']) : 0.0;
        $building->monthly_charge_enabled = isset($data['monthly_charge_enabled'])
            ? (bool) $data['monthly_charge_enabled']
            : ($building->monthly_charge > 0);
        $building->charge_mode = self::normalizeChargeMode($data['charge_mode'] ?? 'fixed');
        $building->charge_per_person = isset($data['charge_per_person'])
            ? max(0, (float) $data['charge_per_person']) : 0.0;

        $id = $this->repo->create($building);
        $building->id = $id;

        // Ensure hierarchy settings are initialized
        $this->hierarchyRepo->findOrCreateByBuildingId($id);

        // سازنده ساختمان به‌صورت خودکار مدیر (member) ساختمان می‌شود.
        $db = \App\Core\Database::getConnection();
        $stmt = \App\Core\Database::prepareInsertIgnore(
            $db,
            "INSERT IGNORE INTO building_members (user_id, building_id, role, status, invited_by)
             VALUES (?, ?, 'manager', 'active', ?)"
        );
        $stmt->execute([$userId, $id, $userId]);

        // ساخت خودکار بلوک‌ها از روی نام‌های واردشده در فرم
        $blockIds = [];
        $blocks = $data['blocks'] ?? [];
        if ($building->has_blocks && is_array($blocks)) {
            $bstmt = $db->prepare("INSERT INTO blocks (building_id, name) VALUES (?, ?)");
            foreach ($blocks as $bname) {
                $bname = trim((string) $bname);
                if ($bname !== '') {
                    $bstmt->execute([$id, $bname]);
                    $blockIds[] = (int) $db->lastInsertId();
                }
            }
        }

        // ساخت خودکار طبقات و واحدها بر اساس «تعداد طبقه» و «تعداد واحد» واردشده در فرم
        $this->scaffoldFloorsAndUnits(
            $id,
            $building->total_floors,
            $building->total_units,
            $blockIds
        );

        // ساخت خودکار مشاعات از روی فرم (نام + قابل رزرو بودن)
        $commonAreas = $data['common_areas'] ?? [];
        if (is_array($commonAreas)) {
            $cstmt = $db->prepare(
                "INSERT INTO common_areas (building_id, name, type, bookable) VALUES (?, ?, ?, ?)"
            );
            foreach ($commonAreas as $ca) {
                if (is_string($ca)) {
                    $ca = ['name' => $ca];
                }
                $caname = trim((string) ($ca['name'] ?? ''));
                if ($caname === '') {
                    continue;
                }
                $cstmt->execute([$id, $caname, $ca['type'] ?? 'general', (int) !empty($ca['bookable'])]);
            }
        }

        // پارکینگ: یک مشاع از نوع پارکینگ با ظرفیت مشخص
        if ($building->parking_spots > 0) {
            $pstmt = $db->prepare(
                "INSERT INTO common_areas (building_id, name, type, description, bookable) VALUES (?, ?, 'parking', ?, 0)"
            );
            $pstmt->execute([$id, 'پارکینگ', 'ظرفیت: ' . $building->parking_spots . ' خودرو']);
        }

        \App\Core\Audit::log($userId, 'building.create', 'building', $id, $id, [
            'name' => $building->name,
        ]);
        return $building;
    }

    /**
     * ساخت خودکار طبقات و واحدها هنگام ثبت ساختمان.
     *
     * تا پیش از این، «تعداد طبقه» و «تعداد واحد» فقط به‌عنوان عدد روی ساختمان
     * ذخیره می‌شد و هیچ رکورد واقعی در جدول‌های floors/units ساخته نمی‌شد؛
     * به همین دلیل کاربر فکر می‌کرد این اطلاعات ثبت نشده است.
     *
     * @param list<int> $blockIds شناسه بلوک‌های ساخته‌شده (در صورت وجود)
     */
    private function scaffoldFloorsAndUnits(
        int $buildingId,
        ?int $totalFloors,
        ?int $totalUnits,
        array $blockIds = []
    ): void {
        $totalFloors = $totalFloors !== null ? max(0, $totalFloors) : 0;
        $totalUnits = $totalUnits !== null ? max(0, $totalUnits) : 0;
        if ($totalFloors === 0 && $totalUnits === 0) {
            return;
        }

        $db = \App\Core\Database::getConnection();

        // اگر طبقه اعلام نشده ولی واحد داریم، همه واحدها در یک طبقه قرار می‌گیرند
        $floorCount = $totalFloors > 0 ? $totalFloors : 1;
        // بلوک‌ها: اگر بلوکی نداریم، یک «بلوک مجازی» با شناسه null
        $targets = !empty($blockIds) ? $blockIds : [null];

        try {
            $floorStmt = $db->prepare(
                "INSERT INTO floors (building_id, block_id, floor_number, name) VALUES (?, ?, ?, ?)"
            );
            $unitStmt = $db->prepare(
                "INSERT INTO units (building_id, block_id, floor_id, unit_number, type) VALUES (?, ?, ?, ?, 'residential')"
            );

            // تقسیم واحدها بین طبقات (به‌صورت متوازن؛ باقیمانده به طبقات اول)
            $floorSlots = count($targets) * $floorCount;
            $unitsPerFloor = $floorSlots > 0 ? intdiv($totalUnits, $floorSlots) : 0;
            $remainder = $floorSlots > 0 ? $totalUnits % $floorSlots : 0;

            $unitCounter = 0;
            $slotIndex = 0;
            foreach ($targets as $blockId) {
                for ($n = 1; $n <= $floorCount; $n++) {
                    $floorStmt->execute([$buildingId, $blockId, $n, 'طبقه ' . $n]);
                    $floorId = (int) $db->lastInsertId();

                    $unitsHere = $unitsPerFloor + ($slotIndex < $remainder ? 1 : 0);
                    $slotIndex++;
                    for ($u = 0; $u < $unitsHere; $u++) {
                        $unitCounter++;
                        $unitStmt->execute([$buildingId, $blockId, $floorId, (string) $unitCounter]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ساخت ساختار نباید مانع ثبت ساختمان شود
            Logger::error('BuildingService', 'ساخت خودکار طبقات و واحدها ناموفق بود', [
                'building_id' => $buildingId,
            ], $e);
        }
    }

    public function getBuildingById(int $id, int $userId): ?Building
    {
        $building = $this->repo->findById($id);
        if (!$building) {
            return null;
        }
        if ($userId > 0) {
            $building->my_role = $this->repo->findMemberRole($userId, $id);
        }
        return $building;
    }

    public function listBuildingsForUser(int $userId): array
    {
        return $this->repo->findByUserId($userId);
    }

    public function updateHierarchySettings(int $buildingId, array $settings): bool
    {
        return $this->hierarchyRepo->updateByBuildingId($buildingId, $settings);
    }

    /**
     * ویرایش ساختمان (نام، آدرس، نام سفارشی، رنگ تم، مشخصات ساختمانی، شارژ ثابت و ...).
     * فیلدهای ارسال‌نشده روی مقادیر قبلی باقی می‌مانند.
     */
    public function updateBuilding(int $id, array $data, int $actorUserId = 0): ?Building
    {
        $building = $this->repo->findById($id);
        if (!$building) {
            return null;
        }

        $building->name = $data['name'] ?? $building->name;
        $building->address = $data['address'] ?? $building->address;
        $building->custom_name = $data['custom_name'] ?? $building->custom_name;
        $building->theme_color = $data['theme_color'] ?? $building->theme_color;
        if (array_key_exists('total_units', $data)) {
            $building->total_units = $data['total_units'] !== '' && $data['total_units'] !== null
                ? (int) $data['total_units'] : null;
        }
        if (array_key_exists('total_floors', $data)) {
            $building->total_floors = $data['total_floors'] !== '' && $data['total_floors'] !== null
                ? (int) $data['total_floors'] : null;
        }
        if (array_key_exists('has_blocks', $data)) {
            $building->has_blocks = (bool) $data['has_blocks'];
        }
        if (array_key_exists('default_image', $data)) {
            $building->default_image = (string) $data['default_image'];
        }
        if (array_key_exists('parking_spots', $data)) {
            $building->parking_spots = max(0, (int) $data['parking_spots']);
        }
        if (array_key_exists('monthly_charge', $data)) {
            $building->monthly_charge = max(0, (float) $data['monthly_charge']);
        }
        if (array_key_exists('monthly_charge_enabled', $data)) {
            $building->monthly_charge_enabled = (bool) $data['monthly_charge_enabled'];
        }
        if (array_key_exists('charge_mode', $data)) {
            $building->charge_mode = self::normalizeChargeMode($data['charge_mode']);
        }
        if (array_key_exists('charge_per_person', $data)) {
            $building->charge_per_person = max(0, (float) $data['charge_per_person']);
        }

        if (!empty($data['hierarchy_settings']) && is_array($data['hierarchy_settings'])) {
            $building->hierarchy_settings = $data['hierarchy_settings'];
            $this->updateHierarchySettings($id, $data['hierarchy_settings']);
        }

        $this->repo->update($building);
        \App\Core\Audit::log($actorUserId, 'building.update', 'building', $id, $id, [
            'name' => $building->name,
        ]);
        return $this->repo->findById($id);
    }

    /**
     * اعتبارسنجی حالت شارژ. مقادیر مجاز: fixed | per_person | custom
     */
    public static function normalizeChargeMode($mode): string
    {
        $mode = is_string($mode) ? $mode : 'fixed';
        return in_array($mode, ['fixed', 'per_person', 'custom'], true) ? $mode : 'fixed';
    }

    public function deleteBuilding(int $buildingId, int $actorUserId = 0): bool
    {
        $deleted = $this->repo->delete($buildingId);
        if ($deleted) {
            \App\Core\Audit::log($actorUserId, 'building.delete', 'building', $buildingId, $buildingId, []);
        }
        return $deleted;
    }
}
