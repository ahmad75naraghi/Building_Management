<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\PushService;

/**
 * اعلان فوری وب (Web Push) — مدیریت اشتراک دستگاه‌ها.
 */
final class PushController
{
    /** GET /api/push/public-key — کلید عمومی برای ثبت اشتراک مرورگر */
    public function publicKey(Request $request): Response
    {
        if (!PushService::enabled()) {
            return (new Response())->setJson(['success' => true, 'enabled' => false]);
        }
        return (new Response())->setJson([
            'success' => true,
            'enabled' => true,
            'public_key' => PushService::publicKey(),
        ]);
    }

    /** GET /api/push/status — وضعیت پوش کاربر جاری برای نمایش در پروفایل */
    public function status(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        return (new Response())->setJson([
            'success' => true,
            'enabled' => PushService::enabled(),
            'subscribed_devices' => PushService::subscriptionCount($userId),
        ]);
    }

    /** POST /api/push/subscribe — ثبت اشتراک دستگاه */
    public function subscribe(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        if ($userId <= 0) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        if (!PushService::enabled()) {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false, 'message' => 'اعلان فوری در این سرور فعال نیست',
            ]);
        }

        $data = $request->getJsonBody() ?? [];
        $endpoint = trim((string) ($data['endpoint'] ?? ''));
        $p256dh = trim((string) ($data['keys']['p256dh'] ?? ''));
        $auth = trim((string) ($data['keys']['auth'] ?? ''));

        if ($endpoint === '' || mb_strlen($endpoint) > 1024 || !str_starts_with($endpoint, 'https://')) {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false, 'message' => 'آدرس اشتراک معتبر نیست',
            ]);
        }
        if ($p256dh === '' || $auth === '') {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false, 'message' => 'کلیدهای اشتراک ناقص است',
            ]);
        }

        $ok = PushService::saveSubscription(
            $userId,
            $endpoint,
            $p256dh,
            $auth,
            $request->getHeader('User-Agent')
        );
        if (!$ok) {
            return (new Response())->setStatusCode(500)->setJson([
                'success' => false, 'message' => 'ثبت اشتراک ناموفق بود',
            ]);
        }
        return (new Response())->setJson(['success' => true, 'message' => 'اعلان فوری فعال شد']);
    }

    /** POST /api/push/unsubscribe — حذف اشتراک دستگاه */
    public function unsubscribe(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $endpoint = trim((string) (($request->getJsonBody() ?? [])['endpoint'] ?? ''));
        if ($userId <= 0 || $endpoint === '') {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false, 'message' => 'پارامترهای درخواست ناقص است',
            ]);
        }
        PushService::removeSubscription($userId, $endpoint);
        return (new Response())->setJson(['success' => true, 'message' => 'اشتراک حذف شد']);
    }
}
