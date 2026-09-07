<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ExtraModulesRepository;

/**
 * فهرست لاگ ممیزی اقدامات — فقط مدیر ساختمان.
 * برای پرفورمنس، خروجی با LIMIT محدود می‌شود و از ایندکس
 * (building_id, created_at) استفاده می‌کند.
 */
final class AuditController
{
    public function __construct(
        private ExtraModulesRepository $modules = new ExtraModulesRepository(),
    ) {
    }

    public function index(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $buildingId = (int) ($request->getQueryParam('building_id', '0') ?? '0');
        if (!$userId || $buildingId <= 0) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication and building_id required',
            ]);
        }
        if ($this->modules->memberRole($userId, $buildingId) !== 'manager') {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false, 'message' => 'فقط مدیر ساختمان به لاگ ممیزی دسترسی دارد.',
            ]);
        }

        $limit = min(200, max(10, (int) ($request->getQueryParam('limit', '100') ?? '100')));
        $action = trim((string) ($request->getQueryParam('action', '') ?? ''));

        $db = Database::getConnection();
        $sql = "SELECT a.*, u.name AS user_name
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.building_id = ?";
        $params = [$buildingId];
        if ($action !== '') {
            $sql .= " AND a.action LIKE ?";
            $params[] = $action . '%';
        }
        $sql .= " ORDER BY a.id DESC LIMIT " . $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return (new Response())->setJson([
            'success' => true,
            'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
        ]);
    }
}
