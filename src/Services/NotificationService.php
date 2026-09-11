<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;
use App\Repositories\NotificationRepository;
use App\Utilities\CacheHelper;

final class NotificationService
{
    public function __construct(private NotificationRepository $repo = new NotificationRepository())
    {
    }

    public function createNotification(array $data): Notification
    {
        $notification = new Notification();
        $notification->user_id = (int) ($data['user_id'] ?? 0);
        $notification->building_id = isset($data['building_id']) ? (int) $data['building_id'] : null;
        $notification->notification_type = $data['notification_type'] ?? 'general';
        $notification->title = (string) ($data['title'] ?? 'اعلان جدید');
        $notification->message = $data['message'] ?? null;
        $notification->data = $data['data'] ?? null;

        $id = $this->repo->create($notification);
        $notification->id = $id;

        // Queue for async processing (using Redis as a simple queue)
        CacheHelper::set("notification:queue:{$id}", [
            'notification_id' => $id,
            'user_id' => $notification->user_id,
            'title' => $notification->title,
            'created_at' => time(),
        ], 3600);

        return $notification;
    }

    public function getUserNotifications(int $userId, int $limit = 20): array
    {
        return $this->repo->findByUserId($userId, $limit);
    }

    public function markAsRead(int $notificationId): bool
    {
        return $this->repo->markAsRead($notificationId);
    }

    /**
     * ارسال اعلان به همهٔ اعضای فعال یک ساختمان (پخش سراسری).
     *
     * برای اطلاعیه‌ها، رأی‌گیری‌ها و رویدادهای عمومی ساختمان. خطا در ارسال به
     * یک کاربر نباید کل پخش را متوقف کند؛ هر کاربر جداگانه تلاش می‌شود.
     *
     * @param int      $buildingId      شناسهٔ ساختمان
     * @param string   $notificationType نوع اعلان (مثلاً announcement / vote)
     * @param string   $title           عنوان اعلان
     * @param string   $message         متن اعلان
     * @param array    $data            دادهٔ اضافی (JSON) برای لینک عمیق
     * @param int[]    $excludeUserIds  کاربرانی که اعلان نمی‌گیرند (مثلاً ایجادکننده یا مدیران)
     * @return int تعداد اعلان‌های ارسال‌شده
     */
    public function broadcastToBuilding(
        int $buildingId,
        string $notificationType,
        string $title,
        string $message = '',
        array $data = [],
        array $excludeUserIds = []
    ): int {
        $memberIds = $this->repo->activeMemberIds($buildingId);
        $exclude = array_map('intval', $excludeUserIds);
        $sent = 0;
        foreach ($memberIds as $userId) {
            if (in_array((int) $userId, $exclude, true)) {
                continue;
            }
            try {
                $this->createNotification([
                    'user_id' => (int) $userId,
                    'building_id' => $buildingId,
                    'notification_type' => $notificationType,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data ?: null,
                ]);
                $sent++;
            } catch (\Throwable $e) {
                \App\Core\Logger::error('NotificationService', 'پخش اعلان برای یک کاربر ناموفق بود', [
                    'building_id' => $buildingId,
                    'user_id' => $userId,
                ], $e);
            }
        }
        return $sent;
    }
}
