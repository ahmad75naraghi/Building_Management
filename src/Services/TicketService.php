<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AppException;
use App\Exceptions\AuthException;
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

    public function listComments(int $ticketId): array
    {
        return $this->repo->findCommentsByTicketId($ticketId);
    }

    public function updateStatus(int $id, string $status, ?int $assignedTo = null): bool
    {
        return $this->repo->updateStatus($id, $status, $assignedTo);
    }

    /**
     * ویرایش تیکت توسط صاحب تیکت یا مدیر ساختمان.
     * فقط فیلدهای ارسال‌شده به‌روزرسانی می‌شوند.
     */
    public function updateTicket(int $id, array $data, int $userId): Ticket
    {
        $ticket = $this->repo->findById($id);
        if ($ticket === null) {
            throw new AppException('Ticket not found');
        }
        if ($ticket->user_id !== $userId && !$this->isBuildingManager($userId, $ticket->building_id)) {
            throw new AuthException('شما اجازه ویرایش این تیکت را ندارید.');
        }

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw new ValidationException('عنوان تیکت نمی‌تواند خالی باشد.');
            }
            $ticket->title = $title;
        }
        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            if ($description === '') {
                throw new ValidationException('شرح تیکت نمی‌تواند خالی باشد.');
            }
            $ticket->description = $description;
        }
        if (array_key_exists('category', $data)) {
            $category = (string) $data['category'];
            if (!in_array($category, ['technical', 'financial', 'management', 'complaint', 'suggestion'], true)) {
                throw new ValidationException('دسته‌بندی نامعتبر است.');
            }
            $ticket->category = $category;
        }
        if (array_key_exists('priority', $data)) {
            $priority = (string) $data['priority'];
            if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
                throw new ValidationException('اولویت نامعتبر است.');
            }
            $ticket->priority = $priority;
        }

        $this->repo->update($ticket);
        return $ticket;
    }

    /**
     * حذف تیکت توسط صاحب تیکت (وقتی هنوز باز یا رد شده است) یا مدیر ساختمان (همیشه).
     */
    public function deleteTicket(int $id, int $userId): bool
    {
        $ticket = $this->repo->findById($id);
        if ($ticket === null) {
            throw new AppException('Ticket not found');
        }
        $isManager = $this->isBuildingManager($userId, $ticket->building_id);
        if ($ticket->user_id === $userId && !$isManager && !in_array($ticket->status, ['open', 'rejected'], true)) {
            throw new AuthException('تیکت در حال رسیدگی است؛ فقط مدیر ساختمان می‌تواند آن را حذف کند.');
        }
        if ($ticket->user_id !== $userId && !$isManager) {
            throw new AuthException('شما اجازه حذف این تیکت را ندارید.');
        }
        return $this->repo->delete($id);
    }

    private function isBuildingManager(int $userId, int $buildingId): bool
    {
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare(
            "SELECT 1 FROM building_members
             WHERE user_id = ? AND building_id = ? AND role = 'manager' AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$userId, $buildingId]);
        return (bool) $stmt->fetchColumn();
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
