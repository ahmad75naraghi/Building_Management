<?php

declare(strict_types=1);

namespace App\Services;

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

        $id = $this->repo->create($building);
        $building->id = $id;

        // Ensure hierarchy settings are initialized
        $this->hierarchyRepo->findOrCreateByBuildingId($id);

        // سازنده ساختمان به‌صورت خودکار مدیر (member) ساختمان می‌شود.
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare(
            "INSERT IGNORE INTO building_members (user_id, building_id, role, status, invited_by)
             VALUES (?, ?, 'manager', 'active', ?)"
        );
        $stmt->execute([$userId, $id, $userId]);

        // ساخت خودکار بلوک‌ها از روی نام‌های واردشده در فرم
        $blocks = $data['blocks'] ?? [];
        if ($building->has_blocks && is_array($blocks)) {
            $bstmt = $db->prepare("INSERT INTO blocks (building_id, name) VALUES (?, ?)");
            foreach ($blocks as $bname) {
                $bname = trim((string) $bname);
                if ($bname !== '') {
                    $bstmt->execute([$id, $bname]);
                }
            }
        }

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

        return $building;
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
    public function updateBuilding(int $id, array $data): ?Building
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

        if (!empty($data['hierarchy_settings']) && is_array($data['hierarchy_settings'])) {
            $building->hierarchy_settings = $data['hierarchy_settings'];
            $this->updateHierarchySettings($id, $data['hierarchy_settings']);
        }

        $this->repo->update($building);
        return $this->repo->findById($id);
    }

    public function deleteBuilding(int $buildingId): bool
    {
        return $this->repo->delete($buildingId);
    }
}
