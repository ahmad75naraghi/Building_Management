<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;

final class NotificationController
{
    private NotificationService $service;

    public function __construct()
    {
        $this->service = new NotificationService();
    }

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $notifications = $this->service->getUserNotifications((int) $userId, 20);
        return (new Response())->setJson([
            'success' => true,
            'data' => array_map(fn($n) => $n->toArray(), $notifications),
        ]);
    }

    public function markAsRead(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = (int) ($request->getAttribute('id') ?? 0);
        if (!$userId || !$id) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or notification required',
            ]);
        }
        $updated = $this->service->markAsRead($id);
        return (new Response())->setJson([
            'success' => $updated,
            'message' => $updated ? 'Marked as read' : 'Failed to mark as read',
        ]);
    }

    public function store(Request $request): Response
    {
        // Internal/admin endpoint to create notifications
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        $notification = $this->service->createNotification($data);
        return (new Response())->setStatusCode(201)->setJson([
            'success' => true,
            'message' => 'Notification created',
            'data' => $notification->toArray(),
        ]);
    }
}
