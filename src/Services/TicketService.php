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
        \App\Core\Audit::log($userId, 'ticket.create', 'ticket', $id, $ticket->building_id, [
            'title' => $ticket->title,
        ]);
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

    public function updateStatus(int $id, string $status, ?int $assignedTo = null, int $actorUserId = 0): bool
    {
        $ticket = $this->repo->findById($id);
        $updated = $this->repo->updateStatus($id, $status, $assignedTo);

        // اعلان تغییر وضعیت به ایجادکنندهٔ تیکت (اگر خودش تغییر نداد)
        if ($updated && $ticket && (int) $ticket->user_id !== $actorUserId) {
            $labels = [
                'open' => 'باز شد',
                'in_progress' => 'در حال بررسی است',
                'resolved' => 'حل شد ✅',
                'closed' => 'بسته شد',
                'rejected' => 'رد شد',
            ];
            try {
                (new NotificationService())->createNotification([
                    'user_id' => (int) $ticket->user_id,
                    'building_id' => (int) $ticket->building_id,
                    'notification_type' => 'ticket',
                    'title' => 'وضعیت تیکت «' . $ticket->title . '»',
                    'message' => 'تیکت شما ' . ($labels[$status] ?? 'به‌روزرسانی شد') . '.',
                    'data' => ['ticket_id' => $id],
                ]);
            } catch (\Throwable $e) {
                \App\Core\Logger::error('TicketService', 'اعلان تغییر وضعیت تیکت ارسال نشد', ['ticket_id' => $id], $e);
            }
        }
        return $updated;
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
        \App\Core\Audit::log($userId, 'ticket.update', 'ticket', $id, $ticket->building_id, [
            'title' => $ticket->title, 'status' => $ticket->status,
        ]);
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
        $deleted = $this->repo->delete($id);
        if ($deleted) {
            \App\Core\Audit::log($userId, 'ticket.delete', 'ticket', $id, $ticket->building_id, [
                'title' => $ticket->title,
            ]);
        }
        return $deleted;
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
        $commentId = (int) $db->lastInsertId();
        \App\Core\Audit::log($userId, 'ticket.comment', 'ticket', $ticketId, null, []);

        // اعلان دوطرفه: پاسخ‌ها برای طرف مقابل تیکت ارسال می‌شود
        // (یادداشت‌های داخلی هرگز اعلان نمی‌شوند)
        if (empty($data['is_internal'])) {
            $this->notifyTicketReply($ticketId, $userId);
        }

        return ['id' => $commentId, 'ticket_id' => $ticketId];
    }

    /**
     * اعلان پاسخ جدید در تیکت — دوطرفه:
     *  - اگر مدیر/غیر از ایجادکننده پاسخ دهد → به ایجادکنندهٔ تیکت خبر داده می‌شود
     *  - اگر خودِ ایجادکننده پاسخ دهد → به مدیر ساختمان خبر داده می‌شود
     */
    private function notifyTicketReply(int $ticketId, int $commenterId): void
    {
        $ticket = $this->repo->findById($ticketId);
        if (!$ticket) {
            return;
        }
        $creatorId = (int) $ticket->user_id;

        if ($commenterId !== $creatorId) {
            $targetUserId = $creatorId;
            $title = 'پاسخ جدید به تیکت «' . $ticket->title . '»';
            $message = 'به تیکت شما پاسخ داده شد. برای مشاهده گفت‌وگو، تیکت را باز کنید.';
        } else {
            // پاسخ خود ساکن → اطلاع به مدیر ساختمان
            $targetUserId = $this->buildingManagerId((int) $ticket->building_id);
            if ($targetUserId <= 0 || $targetUserId === $commenterId) {
                return;
            }
            $title = 'پیام جدید در تیکت «' . $ticket->title . '»';
            $message = 'ساکن در تیکت پیام جدیدی گذاشته است.';
        }

        try {
            (new NotificationService())->createNotification([
                'user_id' => $targetUserId,
                'building_id' => (int) $ticket->building_id,
                'notification_type' => 'ticket',
                'title' => $title,
                'message' => $message,
                'data' => ['ticket_id' => $ticketId],
            ]);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('TicketService', 'اعلان پاسخ تیکت ارسال نشد', ['ticket_id' => $ticketId], $e);
        }
    }

    /** شناسهٔ مدیر فعال ساختمان (۰ اگر نبود) */
    private function buildingManagerId(int $buildingId): int
    {
        $stmt = \App\Core\Database::getConnection()->prepare(
            "SELECT user_id FROM building_members
             WHERE building_id = ? AND role = 'manager' AND status = 'active'
             ORDER BY id LIMIT 1"
        );
        $stmt->execute([$buildingId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
