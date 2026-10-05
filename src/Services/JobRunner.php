<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * پردازشگر صف کار: کارها را از صف می‌گیرد و به هندلر مناسب می‌سپارد.
 * هندلرها:
 *   sms          → ارسال پیامک از طریق ملی‌پیامک (با تلاش مجدد خودکار)
 *   (قابل توسعه با افزودن شاخه‌های تازه در processJob)
 */
final class JobRunner
{
    /**
     * اجرای حداکثر $maxJobs کار از صف.
     *
     * @return array{processed:int, succeeded:int, failed:int}
     */
    public function run(int $maxJobs = 50): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

        while ($result['processed'] < $maxJobs) {
            $job = JobQueue::claim();
            if ($job === null) {
                break;
            }
            $result['processed']++;

            try {
                $this->processJob($job);
                JobQueue::complete($job['id']);
                $result['succeeded']++;
            } catch (\Throwable $e) {
                Logger::error('JobRunner', 'اجرای کار ناموفق بود', [
                    'job_id' => $job['id'],
                    'job_type' => $job['job_type'],
                ], $e);
                JobQueue::fail($job['id'], $job['attempts'], $job['max_attempts'], $e->getMessage());
                $result['failed']++;
            }
        }

        if ($result['processed'] > 0) {
            Logger::info('JobRunner', 'چرخه پردازش صف انجام شد', $result);
        }
        return $result;
    }

    /** اجرای یک کار بر اساس نوع آن */
    private function processJob(array $job): void
    {
        $payload = $job['payload'];
        switch ($job['job_type']) {
            case 'sms':
                $to = (string) ($payload['to'] ?? '');
                $text = (string) ($payload['text'] ?? '');
                $args = (array) ($payload['args'] ?? []);
                $bodyId = isset($payload['body_id']) ? (int) $payload['body_id'] : null;
                if ($to === '' || $text === '') {
                    throw new \RuntimeException('پیامک بدون مقصد یا متن است');
                }
                // ارسال مستقیم (نه از مسیر صف، تا حلقه ایجاد نشود)
                $ok = (new SmsService())->sendDirect($to, $text, $args, $bodyId);
                if (!$ok) {
                    throw new \RuntimeException('ارسال پیامک ناموفق بود');
                }
                return;

            case 'notification':
                (new NotificationService())->createNotification($payload);
                return;

            default:
                throw new \RuntimeException('نوع کار ناشناخته است: ' . $job['job_type']);
        }
    }
}
