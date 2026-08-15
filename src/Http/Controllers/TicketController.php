<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\TicketService;

final class TicketController
{
    private TicketService $service;

    public function __construct()
    {
        $this->service = new TicketService();
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $ticket = $this->service->createTicket($data, (int) $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Ticket created successfully',
                'data' => $ticket->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $buildingId = (int) ($request->getQueryParam('building_id') ?? 0);
        if ($buildingId > 0) {
            $tickets = $this->service->listByBuilding($buildingId);
        } else {
            $tickets = $this->service->listByUser((int) $userId);
        }
        return (new Response())->setJson([
            'success' => true,
            'data' => array_map(fn($t) => $t->toArray(), $tickets),
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) ($request->getAttribute('id') ?? 0);
        $ticket = $this->service->getTicketById($id);
        if (!$ticket) {
            return (new Response())->setStatusCode(404)->setJson([
                'success' => false, 'message' => 'Ticket not found',
            ]);
        }
        return (new Response())->setJson([
            'success' => true,
            'data' => $ticket->toArray(),
        ]);
    }

    public function updateStatus(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $id = (int) ($request->getAttribute('id') ?? 0);
        $data = $request->getJsonBody() ?? [];
        $updated = $this->service->updateStatus($id, $data['status'] ?? 'open', isset($data['assigned_to']) ? (int) $data['assigned_to'] : null);
        return (new Response())->setJson([
            'success' => $updated,
            'message' => $updated ? 'Status updated' : 'Failed to update status',
        ]);
    }

    public function addComment(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $ticketId = (int) ($request->getAttribute('ticket_id') ?? 0);
        if (!$userId || !$ticketId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or ticket required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $comment = $this->service->addComment($ticketId, $data, (int) $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Comment added',
                'data' => $comment,
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
