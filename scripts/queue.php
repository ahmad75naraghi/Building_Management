<?php

declare(strict_types=1);

/**
 * پردازشگر صف کار پس‌زمینه — اجرای خودکار توسط کران.
 *
 * کارهای سنگین/بیرونی (مثل ارسال پیامک) از درخواست کاربر جدا می‌شوند و
 * در صف جدول `jobs` قرار می‌گیرند؛ این اسکریپت آن‌ها را پردازش می‌کند.
 *
 * اجرا دستی:        php scripts/queue.php
 * کرون پیشنهادی:    هر دقیقه
 *   * * * * * /usr/bin/php /path/to/Building_Management/scripts/queue.php >> /var/log/bm_queue.log 2>&1
 *
 * فعال‌سازی صف: در `.env` مقدار `QUEUE_DRIVER=database` قرار گیرد.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\JobQueue;
use App\Services\JobRunner;

if (!JobQueue::isAvailable()) {
    echo 'jobs table not migrated yet. Run: php scripts/migrator.php' . PHP_EOL;
    exit(0);
}

$max = max(1, (int) (getenv('QUEUE_BATCH_SIZE') ?: 50));
$result = (new JobRunner())->run($max);

echo sprintf(
    "Queue: %d processed, %d succeeded, %d failed.%s",
    $result['processed'],
    $result['succeeded'],
    $result['failed'],
    PHP_EOL
);
