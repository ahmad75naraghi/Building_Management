<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Notification;
use PDO;

final class NotificationRepository
{
    public function create(Notification $notification): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, building_id, notification_type, title, message, data, is_read, is_email_sent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $notification->user_id,
            $notification->building_id,
            $notification->notification_type,
            $notification->title,
            $notification->message,
            $notification->data ? json_encode($notification->data) : null,
            (int) $notification->is_read,
            (int) $notification->is_email_sent,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findByUserId(int $userId, int $limit = 20): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
        return array_map(fn($r) => $this->mapRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function markAsRead(int $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$id]);
    }

    private function mapRow(array $row): Notification
    {
        $n = new Notification();
        $n->id = (int) $row['id'];
        $n->user_id = (int) $row['user_id'];
        $n->building_id = $row['building_id'] ? (int) $row['building_id'] : null;
        $n->notification_type = $row['notification_type'];
        $n->title = $row['title'];
        $n->message = $row['message'];
        $n->data = $row['data'] ? json_decode($row['data'], true) : null;
        $n->is_read = (bool) $row['is_read'];
        $n->is_email_sent = (bool) $row['is_email_sent'];
        $n->read_at = $row['read_at'];
        $n->created_at = $row['created_at'];
        return $n;
    }
}
