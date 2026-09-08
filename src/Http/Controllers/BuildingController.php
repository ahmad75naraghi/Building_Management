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
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $id = (int) ($request->getAttribute('id') ?? 0);
        if (!$userId || !$id) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication and building id required',
            ]);
        }
        if (!$this->isBuildingMember($userId, $id)) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false,
                'message' => 'You are not a member of this building',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $building = $this->service->updateBuilding($id, $data, $userId);
            if (!$building) {
                return (new Response())->setStatusCode(404)->setJson([
                    'success' => false,
                    'message' => 'Building not found',
                ]);
            }
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Building updated',
                'data' => $building->toArray(),
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
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $id = (int) ($request->getAttribute('id') ?? 0);
        if (!$userId || !$id) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication and building id required',
            ]);
        }
        if (!$this->isBuildingMember($userId, $id)) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false,
                'message' => 'You are not a member of this building',
            ]);
        }
        $deleted = $this->service->deleteBuilding($id, $userId);
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

    /**
     * آیا کاربر مدیر فعال این ساختمان است؟ (ساختار مجتمع فقط با مدیر تغییر می‌کند)
     */
    private function isBuildingManager(int $userId, int $buildingId): bool
    {
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id FROM building_members
             WHERE user_id = ? AND building_id = ? AND role = 'manager' AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$userId, $buildingId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * پاسخ خطای «فقط مدیر» — برای عملیات ساختاری ساختمان.
     */
    private function managerOnlyGuard(Request $request, int $buildingId): ?Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication and building id required',
            ]);
        }
        if (!$this->isBuildingManager($userId, $buildingId)) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false,
                'message' => 'فقط مدیر ساختمان می‌تواند این بخش را تغییر دهد.',
            ]);
        }
        return null;
    }

    /**
     * ویرایش/حذف عمومی برای جدول‌های ساختاری ساختمان (blocks/floors/common_areas).
     *
     * @param array<int,string> $allowed ستون‌های قابل ویرایش
     */
    private function updateStructureRow(Request $request, string $table, array $allowed): Response
    {
        $id = (int) ($request->getAttribute('id') ?? 0);
        $db = \App\Core\Database::getConnection();

        $stmt = $db->prepare("SELECT building_id FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $buildingId = (int) ($stmt->fetchColumn() ?: 0);
        if ($buildingId <= 0) {
            return (new Response())->setStatusCode(404)->setJson([
                'success' => false,
                'message' => 'Record not found',
            ]);
        }
        if ($guard = $this->managerOnlyGuard($request, $buildingId)) {
            return $guard;
        }

        $data = $request->getJsonBody() ?? [];
        $sets = [];
        $values = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $data)) {
                $sets[] = "{$column} = ?";
                $values[] = $data[$column] === '' ? null : $data[$column];
            }
        }
        if (!$sets) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'هیچ فیلدی برای ویرایش ارسال نشده است.',
            ]);
        }
        $values[] = $id;
        $db->prepare("UPDATE {$table} SET " . implode(', ', $sets) . " WHERE id = ?")->execute($values);

        return (new Response())->setJson(['success' => true, 'message' => 'Updated']);
    }

    private function deleteStructureRow(Request $request, string $table): Response
    {
        $id = (int) ($request->getAttribute('id') ?? 0);
        $db = \App\Core\Database::getConnection();

        $stmt = $db->prepare("SELECT building_id FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $buildingId = (int) ($stmt->fetchColumn() ?: 0);
        if ($buildingId <= 0) {
            return (new Response())->setStatusCode(404)->setJson([
                'success' => false,
                'message' => 'Record not found',
            ]);
        }
        if ($guard = $this->managerOnlyGuard($request, $buildingId)) {
            return $guard;
        }

        $db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
        return (new Response())->setJson(['success' => true, 'message' => 'Deleted']);
    }

    public function updateBlock(Request $request): Response
    {
        return $this->updateStructureRow($request, 'blocks', ['name', 'description']);
    }

    public function destroyBlock(Request $request): Response
    {
        return $this->deleteStructureRow($request, 'blocks');
    }

    public function updateFloor(Request $request): Response
    {
        return $this->updateStructureRow($request, 'floors', ['floor_number', 'name', 'block_id']);
    }

    public function destroyFloor(Request $request): Response
    {
        return $this->deleteStructureRow($request, 'floors');
    }

    public function updateCommonArea(Request $request): Response
    {
        return $this->updateStructureRow($request, 'common_areas', ['name', 'type', 'description', 'bookable']);
    }

    public function destroyCommonArea(Request $request): Response
    {
        return $this->deleteStructureRow($request, 'common_areas');
    }

    private function isBuildingMember(int $userId, int $buildingId): bool
    {
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id FROM building_members
             WHERE user_id = ? AND building_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$userId, $buildingId]);
        return (bool) $stmt->fetchColumn();
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
        $building = $this->service->getBuildingById($buildingId, (int) $userId);

        return (new Response())->setJson([
            'success' => $updated,
            'message' => $updated ? 'Hierarchy updated' : 'Failed to update hierarchy',
            'building' => $building,
        ]);
    }

    public function storeBlock(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        if ($guard = $this->managerOnlyGuard($request, $buildingId)) {
            return $guard;
        }
        $data = $request->getJsonBody() ?? [];
        $db = \App\Core\Database::getConnection();

        $stmt = $db->prepare("INSERT INTO blocks (building_id, name, description) VALUES (?, ?, ?)");
        $stmt->execute([$buildingId, $data['name'] ?? null, $data['description'] ?? null]);

        return (new Response())->setStatusCode(201)->setJson([
            'success' => true,
            'message' => 'Block created',
        ]);
    }

    public function indexBlocks(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM blocks WHERE building_id = ? ORDER BY id ASC");
        $stmt->execute([$buildingId]);
        $blocks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return (new Response())->setJson([
            'success' => true,
            'data' => ['building_id' => $buildingId, 'blocks' => $blocks],
        ]);
    }

    public function storeFloor(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        if ($guard = $this->managerOnlyGuard($request, $buildingId)) {
            return $guard;
        }
        $data = $request->getJsonBody() ?? [];
        $db = \App\Core\Database::getConnection();

        $stmt = $db->prepare("INSERT INTO floors (building_id, block_id, floor_number, name) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $buildingId,
            $data['block_id'] ?? null,
            $data['floor_number'] ?? null,
            $data['name'] ?? null
        ]);

        return (new Response())->setStatusCode(201)->setJson([
            'success' => true,
            'message' => 'Floor created',
        ]);
    }

    public function indexFloors(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM floors WHERE building_id = ? ORDER BY floor_number ASC");
        $stmt->execute([$buildingId]);
        $floors = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return (new Response())->setJson([
            'success' => true,
            'data' => ['building_id' => $buildingId, 'floors' => $floors],
        ]);
    }

    public function storeUnit(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $buildingId = (int) $request->getAttribute('building_id');
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication and building id required',
            ]);
        }
        if (!$this->isBuildingMember($userId, $buildingId)) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false,
                'message' => 'You are not a member of this building',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $unit = $this->unitService()->createUnit($data, $buildingId, $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Unit created',
                'data' => $unit->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function indexUnits(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $buildingId = (int) $request->getAttribute('building_id');
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication and building id required',
            ]);
        }
        if (!$this->isBuildingMember($userId, $buildingId)) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false,
                'message' => 'You are not a member of this building',
            ]);
        }
        $units = $this->unitService()->listUnitsByBuilding($buildingId);
        return (new Response())->setJson([
            'success' => true,
            'data' => [
                'building_id' => $buildingId,
                'units' => array_map(fn($u) => $u->toArray(), $units),
            ],
        ]);
    }

    public function updateUnit(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $unitId = (int) ($request->getAttribute('id') ?? 0);
        if (!$userId || !$unitId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication and unit id required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $unit = $this->unitService()->updateUnit($unitId, $data, $userId);
            if (!$unit) {
                return (new Response())->setStatusCode(404)->setJson([
                    'success' => false,
                    'message' => 'Unit not found',
                ]);
            }
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Unit updated',
                'data' => $unit->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function destroyUnit(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $unitId = (int) ($request->getAttribute('id') ?? 0);
        if (!$userId || !$unitId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication and unit id required',
            ]);
        }
        try {
            $deleted = $this->unitService()->deleteUnit($unitId, $userId);
            if (!$deleted) {
                return (new Response())->setStatusCode(404)->setJson([
                    'success' => false,
                    'message' => 'Unit not found',
                ]);
            }
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Unit deleted',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function unitService(): \App\Services\UnitService
    {
        return new \App\Services\UnitService();
    }

    public function storeCommonArea(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        if ($guard = $this->managerOnlyGuard($request, $buildingId)) {
            return $guard;
        }
        $data = $request->getJsonBody() ?? [];
        $db = \App\Core\Database::getConnection();

        $stmt = $db->prepare("INSERT INTO common_areas (building_id, name, type, description, bookable) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $buildingId,
            $data['name'] ?? '',
            $data['type'] ?? null,
            $data['description'] ?? null,
            (int)($data['bookable'] ?? 0)
        ]);

        return (new Response())->setStatusCode(201)->setJson([
            'success' => true,
            'message' => 'Common area created',
        ]);
    }

    public function indexCommonAreas(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM common_areas WHERE building_id = ? ORDER BY id ASC");
        $stmt->execute([$buildingId]);
        $commonAreas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return (new Response())->setJson([
            'success' => true,
            'data' => ['building_id' => $buildingId, 'common_areas' => $commonAreas],
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
            $result = $invitationService->createInvitation($data, (int) $userId);
            $invitation = $result['invitation'];
            $payload = $invitation->toArray();
            $payload['sms_sent'] = $result['sms_sent'];
            $payload['invite_link'] = InvitationService::inviteLink($invitation->token);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => $result['sms_sent'] ? 'دعوتنامه ساخته و پیامک ارسال شد.' : 'دعوتنامه ساخته شد ولی پیامک ارسال نشد؛ لینک را دستی ارسال کنید.',
                'data' => $payload,
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function indexInvitations(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $buildingId = (int) ($request->getAttribute('building_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication and building id required',
            ]);
        }
        if (!$this->isBuildingMember($userId, $buildingId)) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false,
                'message' => 'You are not a member of this building',
            ]);
        }
        try {
            $invitations = (new InvitationService())->listInvitations($buildingId);
            return (new Response())->setJson([
                'success' => true,
                'data' => array_map(fn($inv) => $inv->toArray(), $invitations),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ارسال مجدد پیامک دعوت (مدیر ساختمان).
     * POST /api/invitations/{id}/resend با building_id در بدنه
     */
    public function resendInvitation(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $invitationId = (int) ($request->getAttribute('id') ?? 0);
        $data = $request->getJsonBody() ?? [];
        $buildingId = (int) ($data['building_id'] ?? 0);
        if (!$userId || !$invitationId || !$buildingId) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'invitation id and building_id are required',
            ]);
        }
        if (!$this->isBuildingMember($userId, $buildingId)) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false,
                'message' => 'You are not a member of this building',
            ]);
        }
        try {
            $sent = (new InvitationService())->resendSms($invitationId, $buildingId);
            return (new Response())->setJson([
                'success' => $sent,
                'message' => $sent ? 'پیامک دعوت مجدداً ارسال شد.' : 'ارسال پیامک ناموفق بود.',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * لغو دعوت‌نامه — فقط مدیر ساختمان، فقط دعوت‌های در انتظار پذیرش.
     */
    public function revokeInvitation(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $invitationId = (int) ($request->getAttribute('id') ?? 0);
        if (!$userId || !$invitationId) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'invitation id is required',
            ]);
        }
        try {
            $invitation = (new InvitationService())->revokeInvitation($invitationId, $userId);
            return (new Response())->setJson([
                'success' => true,
                'message' => 'دعوت‌نامه لغو شد.',
                'data' => ['id' => $invitation->id, 'status' => $invitation->status],
            ]);
        } catch (\App\Exceptions\AuthException $e) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\App\Exceptions\ValidationException $e) {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $status = $e->getMessage() === 'Invitation not found' ? 404 : 400;
            return (new Response())->setStatusCode($status)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function invitationInfo(Request $request): Response
    {
        $token = trim((string) ($request->getQueryParam('token') ?? ''));
        if ($token === '') {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'token query parameter is required',
            ]);
        }
        try {
            $invitationService = new InvitationService();
            $info = $invitationService->getInvitationInfo($token);
            if (!$info) {
                return (new Response())->setStatusCode(404)->setJson([
                    'success' => false,
                    'message' => 'Invitation not found or already used',
                ]);
            }
            return (new Response())->setJson([
                'success' => true,
                'data' => $info,
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
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $buildingId = (int) $request->getAttribute('building_id');
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication and building id required',
            ]);
        }
        if (!$this->isBuildingMember($userId, $buildingId)) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false,
                'message' => 'You are not a member of this building',
            ]);
        }
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare(
            "SELECT bm.*, u.name, u.email, u.phone
             FROM building_members bm
             INNER JOIN users u ON bm.user_id = u.id
             WHERE bm.building_id = ?"
        );
        $stmt->execute([$buildingId]);
        $members = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // همهٔ واحدهای ساختمان یک‌بار خوانده و بین اعضا تقسیم می‌شود (بدون کوئری اضافی)
        $unitStmt = $db->prepare(
            "SELECT id, unit_number, owner_user_id, tenant_user_id, owner_resident, parking_no, storage_no
             FROM units
             WHERE building_id = ?
             ORDER BY unit_number ASC"
        );
        $unitStmt->execute([$buildingId]);
        $allUnits = $unitStmt->fetchAll(\PDO::FETCH_ASSOC);

        $members = array_map(function (array $m) use ($allUnits): array {
            $memberId = (int) $m['user_id'];
            $m['units'] = [];
            foreach ($allUnits as $u) {
                $owner = $u['owner_user_id'] !== null ? (int) $u['owner_user_id'] : null;
                $tenant = $u['tenant_user_id'] !== null ? (int) $u['tenant_user_id'] : null;
                if ($owner !== $memberId && $tenant !== $memberId) {
                    continue;
                }
                $relation = $owner === $memberId ? 'owner' : 'tenant';
                if ($relation === 'owner' && $tenant === null && (bool) $u['owner_resident']) {
                    $relation = 'owner_resident';
                }
                $m['units'][] = [
                    'id' => (int) $u['id'],
                    'unit_number' => $u['unit_number'],
                    'relation' => $relation, // owner | owner_resident | tenant
                    'parking_no' => $u['parking_no'] ?? null,
                    'storage_no' => $u['storage_no'] ?? null,
                ];
            }
            return $m;
        }, $members);

        return (new Response())->setJson([
            'success' => true,
            'data' => $members,
        ]);
    }

    /**
     * ساخت/اتصال گروهی کاربران و انتساب به واحدها — فقط مدیر ساختمان.
     * بدنه: { rows: [{name, phone, unit_number|unit_id, role, password?}], force?: bool }
     */
    public function bulkCreateUsers(Request $request): Response
    {
        $buildingId = (int) $request->getAttribute('building_id');
        if ($guard = $this->managerOnlyGuard($request, $buildingId)) {
            return $guard;
        }
        $managerId = (int) ($request->getAttribute('user_id') ?? 0);

        $body = $request->getJsonBody() ?? [];
        $rows = $body['rows'] ?? [];
        if (!is_array($rows)) {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false,
                'message' => 'فیلد rows باید آرایه‌ای از ردیف‌ها باشد.',
            ]);
        }

        $force = !empty($body['force']);
        $defaultPassword = trim((string) ($body['default_password'] ?? ''));
        if ($defaultPassword !== '' && mb_strlen($defaultPassword) < 6) {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false,
                'message' => 'رمز پیش‌فرض باید حداقل ۶ کاراکتر باشد.',
            ]);
        }

        // اعمال رمز پیش‌فرض روی ردیف‌هایی که رمز ندارند
        if ($defaultPassword !== '') {
            foreach ($rows as &$r) {
                if (is_array($r) && trim((string) ($r['password'] ?? '')) === '') {
                    $r['password'] = $defaultPassword;
                }
            }
            unset($r);
        }

        try {
            $outcome = (new \App\Services\BulkUserService())
                ->createBulk($buildingId, $rows, $managerId, $force);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        $allFailed = $outcome['summary']['failed'] === count($rows);
        return (new Response())->setStatusCode($allFailed ? 422 : 200)->setJson([
            'success' => true,
            'message' => sprintf(
                '%d ساخته شد، %d متصل شد، %d رد شد، %d خطا',
                $outcome['summary']['created'],
                $outcome['summary']['linked'],
                $outcome['summary']['skipped'],
                $outcome['summary']['failed']
            ),
            'data' => $outcome,
        ]);
    }
}
