<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Repositories\TicketRepository;
use App\Utilities\Validator;

final class TicketService
{
    public function __construct(private TicketRepository $repo = new TicketRepository())
    {
    }

    public function createTicket(array $data, int $userId): Ticket
    {
        $errors = Validator::validate($data, [
            'building_id' => 'required',
            'title' => 'required',
            'description' => 'required',
        ]);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $ticket = new Ticket();
        $ticket->building_id = (int) $data['building_id'];
        $ticket->user_id = $userId;
        $ticket->unit_id = isset($data['unit_id']) ? (int) $data['unit_id'] : null;
        $ticket->category = $data['category'] ?? 'technical';
        $ticket->is_anonymous = (bool) ($data['is_anonymous'] ?? false);
        $ticket->title = $data['title'];
        $ticket->description = $data['description'];
        $ticket->priority = $data['priority'] ?? 'normal';
        $ticket->status = 'open';

        $id = $this->repo->create($ticket);
        $ticket->id = $id;
        return $ticket;
    }

    public function getTicketById(int $id): ?Ticket
    {
        return $this->repo->findById($id);
    }

    public function listByBuilding(int $buildingId): array
    {
        return $this->repo->findByBuildingId($buildingId);
    }

    public function listByUser(int $userId): array
    {
        return $this->repo->findByUserId($userId);
    }

    public function updateStatus(int $id, string $status, ?int $assignedTo = null): bool
    {
        return $this->repo->updateStatus($id, $status, $assignedTo);
    }

    public function addComment(int $ticketId, array $data, int $userId): array
    {
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, attachment_path) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $ticketId,
            $userId,
            $data['comment'] ?? '',
            (int) ($data['is_internal'] ?? 0),
            $data['attachment_path'] ?? null,
        ]);
        return ['id' => (int) $db->lastInsertId(), 'ticket_id' => $ticketId];
    }
}
