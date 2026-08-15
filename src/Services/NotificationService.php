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
        $notification->user_id = (int) $data['user_id'];
        $notification->building_id = isset($data['building_id']) ? (int) $data['building_id'] : null;
        $notification->notification_type = $data['notification_type'] ?? 'general';
        $notification->title = $data['title'];
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
}
