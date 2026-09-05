<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Exceptions\AppException;
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

        $id = $this->repo->create($building);
        $building->id = $id;

        // Ensure hierarchy settings are initialized
        $this->hierarchyRepo->findOrCreateByBuildingId($id);

        // سازنده ساختمان به‌صورت خودکار مدیر (member) ساختمان می‌شود.
        // همه ماژول‌ها (واحدها، دعوت‌نامه‌ها، اعضا، ...) عضویت فعال را چک می‌کنند.
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare(
            "INSERT IGNORE INTO building_members (user_id, building_id, role, status, invited_by)
             VALUES (?, ?, 'manager', 'active', ?)"
        );
        $stmt->execute([$userId, $id, $userId]);

        return $building;
    }

    public function getBuildingById(int $id, int $userId): ?Building
    {
        $building = $this->repo->findById($id);
        if (!$building) {
            return null;
        }
        // Authorization check could be done here with a MemberRepository
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
     * ویرایش ساختمان (نام، آدرس، نام سفارشی، رنگ تم و تنظیمات سلسله مراتب).
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
