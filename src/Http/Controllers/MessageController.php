<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\MessageService;

/** صندوق پیام درون‌اپی (چت اعضای ساختمان) */
final class MessageController
{
    public function __construct(private MessageService $service = new MessageService())
    {
    }

    /** GET /api/messages/conversations?building_id= */
    public function conversations(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $buildingId = (int) ($request->getQueryParam('building_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'building_id الزامی است.',
            ]);
        }
        try {
            return (new Response())->setJson([
                'success' => true,
                'data' => $this->service->conversations($buildingId, $userId),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** GET /api/messages/thread/{peer_id}?building_id= */
    public function thread(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $buildingId = (int) ($request->getQueryParam('building_id') ?? 0);
        $otherId = (int) ($request->getAttribute('peer_id') ?? 0);
        if (!$userId || !$buildingId || !$otherId) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'building_id و شناسهٔ کاربر الزامی است.',
            ]);
        }
        try {
            return (new Response())->setJson([
                'success' => true,
                'data' => $this->service->thread($buildingId, $userId, $otherId),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** POST /api/messages  {building_id, recipient_id, body} */
    public function store(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        try {
            $message = $this->service->send($request->getJsonBody() ?? [], $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'پیام ارسال شد.',
                'data' => $message->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** GET /api/messages/unread-count?building_id= */
    public function unreadCount(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $buildingId = (int) ($request->getQueryParam('building_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'building_id الزامی است.',
            ]);
        }
        return (new Response())->setJson([
            'success' => true,
            'data' => ['unread' => $this->service->unreadCount($buildingId, $userId)],
        ]);
    }
}
