<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\Message;
use App\Repositories\MessageRepository;

/**
 * صندوق پیام درون‌اپی — چت بین اعضای یک ساختمان.
 * قانون دسترسی: فرستنده و گیرنده هر دو باید عضو فعال همان ساختمان باشند.
 */
final class MessageService
{
    /** حداکثر طول متن پیام */
    public const MAX_LENGTH = 2000;

    public function __construct(private MessageRepository $repo = new MessageRepository())
    {
    }

    /** آیا کاربر عضو فعال این ساختمان است؟ */
    private function isMember(int $userId, int $buildingId): bool
    {
        $stmt = Database::getConnection()->prepare(
            "SELECT id FROM building_members
             WHERE user_id = ? AND building_id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$userId, $buildingId]);
        return (bool) $stmt->fetchColumn();
    }

    private function requireMember(int $userId, int $buildingId): void
    {
        if (!$this->isMember($userId, $buildingId)) {
            throw new AppException('شما عضو این ساختمان نیستید.');
        }
    }

    /** ارسال پیام جدید */
    public function send(array $data, int $senderId): Message
    {
        $buildingId = (int) ($data['building_id'] ?? 0);
        $recipientId = (int) ($data['recipient_id'] ?? 0);
        $body = trim((string) ($data['body'] ?? ''));

        if ($buildingId <= 0 || $recipientId <= 0) {
            throw new ValidationException('ساختمان و گیرندهٔ پیام مشخص نیست.');
        }
        if ($body === '') {
            throw new ValidationException('متن پیام نمی‌تواند خالی باشد.');
        }
        if (mb_strlen($body, 'UTF-8') > self::MAX_LENGTH) {
            throw new ValidationException('متن پیام بیش از حد طولانی است.');
        }
        if ($recipientId === $senderId) {
            throw new ValidationException('نمی‌توانید به خودتان پیام بدهید.');
        }

        $this->requireMember($senderId, $buildingId);
        if (!$this->isMember($recipientId, $buildingId)) {
            throw new AppException('گیرنده عضو این ساختمان نیست.');
        }

        $message = new Message();
        $message->building_id = $buildingId;
        $message->sender_id = $senderId;
        $message->recipient_id = $recipientId;
        $message->body = $body;
        $message->id = $this->repo->create($message);

        Audit::log($senderId, 'message.send', 'message', $message->id, $buildingId, [
            'recipient_id' => $recipientId,
        ]);

        // اعلان برای گیرنده (شکست اعلان نباید ارسال پیام را خراب کند)
        try {
            $senderName = $this->userName($senderId);
            (new NotificationService())->createNotification([
                'user_id' => $recipientId,
                'building_id' => $buildingId,
                'notification_type' => 'message',
                'title' => 'پیام جدید از ' . $senderName,
                'message' => mb_substr($body, 0, 120, 'UTF-8'),
                'data' => ['from' => $senderId],
            ]);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('MessageService', 'اعلان پیام ارسال نشد', ['reason' => $e->getMessage()]);
        }

        return $message;
    }

    /** فهرست گفتگوهای کاربر در ساختمان */
    public function conversations(int $buildingId, int $userId): array
    {
        $this->requireMember($userId, $buildingId);
        return $this->repo->conversations($buildingId, $userId);
    }

    /** رشتهٔ پیام‌های بین دو کاربر + علامت‌گذاری پیام‌های دریافتی به‌عنوان خوانده‌شده */
    public function thread(int $buildingId, int $userId, int $otherId): array
    {
        $this->requireMember($userId, $buildingId);
        if ($otherId !== $userId) {
            if (!$this->isMember($otherId, $buildingId)) {
                throw new AppException('کاربر مقابل عضو این ساختمان نیست.');
            }
            // ورود به گفتگو = خوانده‌شدن پیام‌های طرف مقابل
            $this->repo->markThreadRead($buildingId, $userId, $otherId);
        }
        return array_map(fn(Message $m) => $m->toArray(), $this->repo->thread($buildingId, $userId, $otherId));
    }

    /** تعداد پیام‌های نخوانده (برای نشان هدر) */
    public function unreadCount(int $buildingId, int $userId): int
    {
        if (!$this->isMember($userId, $buildingId)) {
            return 0;
        }
        return $this->repo->unreadCount($buildingId, $userId);
    }

    private function userName(int $userId): string
    {
        $stmt = Database::getConnection()->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: 'کاربر');
    }
}
