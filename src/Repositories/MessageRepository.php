<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Message;
use PDO;

final class MessageRepository
{
    public function create(Message $m): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO messages (building_id, sender_id, recipient_id, body)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$m->building_id, $m->sender_id, $m->recipient_id, $m->body]);
        return (int) $db->lastInsertId();
    }

    /** گفتگو با یک کاربر: پیام‌های رد و بدل شده بین من و او در ساختمان */
    public function thread(int $buildingId, int $userId, int $otherId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM messages
             WHERE building_id = ?
               AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
             ORDER BY id ASC"
        );
        $stmt->execute([$buildingId, $userId, $otherId, $otherId, $userId]);
        return array_map(fn($r) => $this->mapRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * فهرست گفتگوهای کاربر در ساختمان: آخرین پیام + تعداد نخوانده برای هر طرف.
     */
    public function conversations(int $buildingId, int $userId): array
    {
        $db = Database::getConnection();
        // آخرین پیام هر گفتگو (با هر جهت)
        $stmt = $db->prepare(
            "SELECT m.*
             FROM messages m
             INNER JOIN (
                 SELECT CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END AS other_id,
                        MAX(id) AS last_id
                 FROM messages
                 WHERE building_id = ? AND (sender_id = ? OR recipient_id = ?)
                 GROUP BY other_id
             ) latest ON m.id = latest.last_id
             ORDER BY m.id DESC"
        );
        $stmt->execute([$userId, $buildingId, $userId, $userId]);
        $latest = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // تعداد نخوانده از هر فرستنده
        $unreadStmt = $db->prepare(
            "SELECT sender_id, COUNT(*) AS unread
             FROM messages
             WHERE building_id = ? AND recipient_id = ? AND is_read = 0
             GROUP BY sender_id"
        );
        $unreadStmt->execute([$buildingId, $userId]);
        $unread = [];
        foreach ($unreadStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $unread[(int) $row['sender_id']] = (int) $row['unread'];
        }

        // نام طرف گفتگو
        $names = [];
        if ($latest) {
            $ids = array_unique(array_map(
                static fn($r) => (int) $r['sender_id'] === $userId ? (int) $r['recipient_id'] : (int) $r['sender_id'],
                $latest
            ));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $nameStmt = $db->prepare("SELECT id, name FROM users WHERE id IN ({$placeholders})");
            $nameStmt->execute(array_values($ids));
            foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $names[(int) $row['id']] = $row['name'];
            }
        }

        $out = [];
        foreach ($latest as $row) {
            $otherId = (int) $row['sender_id'] === $userId ? (int) $row['recipient_id'] : (int) $row['sender_id'];
            $m = $this->mapRow($row);
            $out[] = [
                'other_id' => $otherId,
                'other_name' => $names[$otherId] ?? 'کاربر',
                'last_message' => $m->toArray(),
                'last_from_me' => (int) $row['sender_id'] === $userId,
                'unread' => $unread[$otherId] ?? 0,
            ];
        }
        return $out;
    }

    /** تعداد کل پیام‌های نخواندهٔ کاربر در ساختمان */
    public function unreadCount(int $buildingId, int $userId): int
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT COUNT(*) FROM messages WHERE building_id = ? AND recipient_id = ? AND is_read = 0"
        );
        $stmt->execute([$buildingId, $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** خواندن همهٔ پیام‌های یک فرستنده برای کاربر جاری */
    public function markThreadRead(int $buildingId, int $userId, int $senderId): int
    {
        $stmt = Database::getConnection()->prepare(
            "UPDATE messages
             SET is_read = 1, read_at = CURRENT_TIMESTAMP
             WHERE building_id = ? AND recipient_id = ? AND sender_id = ? AND is_read = 0"
        );
        $stmt->execute([$buildingId, $userId, $senderId]);
        return $stmt->rowCount();
    }

    private function mapRow(array $row): Message
    {
        $m = new Message();
        $m->id = (int) $row['id'];
        $m->building_id = (int) $row['building_id'];
        $m->sender_id = (int) $row['sender_id'];
        $m->recipient_id = (int) $row['recipient_id'];
        $m->body = (string) $row['body'];
        $m->is_read = (bool) $row['is_read'];
        $m->read_at = $row['read_at'] ?? null;
        $m->created_at = $row['created_at'] ?? null;
        return $m;
    }
}
