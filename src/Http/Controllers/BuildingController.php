<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\BuildingService;
use App\Services\InvitationService;

final class BuildingController
{
    private BuildingService $service;

    public function __construct()
    {
        $this->service = new BuildingService();
    }

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $buildings = $this->service->listBuildingsForUser((int) $userId);
        return (new Response())->setJson([
            'success' => true,
            'data' => array_map(fn($b) => $b->toArray(), $buildings),
        ]);
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $building = $this->service->createBuilding($data, (int) $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Building created successfully',
                'data' => $building->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function show(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = (int) $request->getAttribute('id');
        $building = $this->service->getBuildingById($id, (int) $userId);
        if (!$building) {
            return (new Response())->setStatusCode(404)->setJson([
                'success' => false,
                'message' => 'Building not found',
            ]);
        }
        return (new Response())->setJson([
            'success' => true,
            'data' => $building->toArray(),
        ]);
    }

    public function update(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = (int) $request->getAttribute('id');
        $data = $request->getJsonBody() ?? [];
        try {
            $building = $this->service->getBuildingById($id, (int) $userId);
            if (!$building) {
                return (new Response())->setStatusCode(404)->setJson([
                    'success' => false,
                    'message' => 'Building not found',
                ]);
            }
            $building->name = $data['name'] ?? $building->name;
            $building->address = $data['address'] ?? $building->address;
            $building->custom_name = $data['custom_name'] ?? $building->custom_name;
            $building->theme_color = $data['theme_color'] ?? $building->theme_color;
            $updated = $this->service->updateHierarchySettings($id, $data['hierarchy_settings'] ?? []);
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Building updated',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = (int) $request->getAttribute('id');
        $deleted = $this->service->deleteBuilding($id);
        if ($deleted) {
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Building deleted',
            ]);
        }
        return (new Response())->setStatusCode(500)->setJson([
            'success' => false,
            'message' => 'Failed to delete building',
        ]);
    }

    public function hierarchySettings(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $buildingId = (int) $request->getAttribute('building_id');
        $building = $this->service->getBuildingById($buildingId, (int) $userId);
        if (!$building) {
            return (new Response())->setStatusCode(404)->setJson([
                'success' => false,
                'message' => 'Building not found',
            ]);
        }
        return (new Response())->setJson([
            'success' => true,
            'data' => $building->hierarchy_settings ?? [],
        ]);
    }

    public function updateHierarchySettings(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $buildingId = (int) $request->getAttribute('building_id');
        $data = $request->getJsonBody() ?? [];
        $updated = $this->service->updateHierarchySettings($buildingId, $data);
        return (new Response())->setJson([
            'success' => $updated,
            'message' => $updated ? 'Hierarchy updated' : 'Failed to update hierarchy',
        ]);
    }

    public function storeBlock(Request $request): Response
    {
        return (new Response())->setJson([
            'success' => true,
            'message' => 'Block created (Phase 2 extension)',
        ]);
    }

    public function indexBlocks(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        return (new Response())->setJson([
            'success' => true,
            'data' => ['building_id' => $buildingId, 'blocks' => []],
        ]);
    }

    public function storeFloor(Request $request): Response
    {
        return (new Response())->setJson([
            'success' => true,
            'message' => 'Floor created (Phase 2 extension)',
        ]);
    }

    public function indexFloors(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        return (new Response())->setJson([
            'success' => true,
            'data' => ['building_id' => $buildingId, 'floors' => []],
        ]);
    }

    public function storeUnit(Request $request): Response
    {
        return (new Response())->setJson([
            'success' => true,
            'message' => 'Unit created (Phase 2 extension)',
        ]);
    }

    public function indexUnits(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        return (new Response())->setJson([
            'success' => true,
            'data' => ['building_id' => $buildingId, 'units' => []],
        ]);
    }

    public function storeCommonArea(Request $request): Response
    {
        return (new Response())->setJson([
            'success' => true,
            'message' => 'Common area created (Phase 2 extension)',
        ]);
    }

    public function indexCommonAreas(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        return (new Response())->setJson([
            'success' => true,
            'data' => ['building_id' => $buildingId, 'common_areas' => []],
        ]);
    }

    public function createInvitation(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $buildingId = (int) ($request->getAttribute('building_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication or building required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        $data['building_id'] = $buildingId;
        try {
            $invitationService = new InvitationService();
            $invitation = $invitationService->createInvitation($data, (int) $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Invitation created',
                'data' => $invitation->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function acceptInvitation(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $data = $request->getJsonBody() ?? [];
        $token = $data['token'] ?? null;
        if (!$userId || !$token) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication and invitation token required',
            ]);
        }
        try {
            $invitationService = new InvitationService();
            $result = $invitationService->acceptInvitation($token, (int) $userId);
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Invitation accepted. You are now a member of the building.',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function members(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("SELECT bm.*, u.name, u.email FROM building_members bm INNER JOIN users u ON bm.user_id = u.id WHERE bm.building_id = ?");
        $stmt->execute([$buildingId]);
        $members = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return (new Response())->setJson([
            'success' => true,
            'data' => $members,
        ]);
    }
}
