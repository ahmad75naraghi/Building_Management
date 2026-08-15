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

    public function deleteBuilding(int $buildingId): bool
    {
        return $this->repo->delete($buildingId);
    }
}
