<?php

declare(strict_types=1);

/**
 * تسک خودکار شارژ ثابت ماهیانه.
 *
 * برای هر ساختمانی که «شارژ ثابت ماهیانه» فعال و مبلغ‌دار است،
 * شارژ ماه جاری را (در صورت عدم وجود) ثبت می‌کند تا به بدهکاری‌ها اضافه شود.
 *
 * اجرا دستی:  php scripts/monthly_charges.php
 * کرون ماهانه (روز اول هر ماه ساعت ۱ بامداد):
 *   0 1 1 * * /usr/bin/php /path/to/Building_Management/scripts/monthly_charges.php >> /var/log/bm_monthly.log 2>&1
 *
 * توجه: مشاهده صفحه مالی (costs.php) هم به‌صورت خودکار شارژ ماه جاری را
 * می‌سازد؛ این اسکریپت برای اطمینان و ارسال آینده پیامک یادآوری است.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Services\CostService;

try {
    $db = Database::getConnection();
} catch (Throwable $e) {
    echo 'DB connection failed: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

// ستون‌های جدید ممکن است هنوز مایگریت نشده باشند
try {
    $rows = $db->query(
        "SELECT id, name, created_by, monthly_charge FROM buildings
         WHERE deleted_at IS NULL AND monthly_charge_enabled = 1 AND monthly_charge > 0"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo 'Monthly-charge columns not migrated yet: ' . $e->getMessage() . PHP_EOL;
    exit(0);
}

$service = new CostService();
$created = 0;
foreach ($rows as $row) {
    $buildingId = (int) $row['id'];
    try {
        $marker = 'auto:monthly:' . date('Y-m');
        $chk = $db->prepare("SELECT id FROM costs WHERE building_id = ? AND description = ? LIMIT 1");
        $chk->execute([$buildingId, $marker]);
        if ($chk->fetchColumn()) {
            echo "building #{$buildingId} ({$row['name']}): already exists, skip" . PHP_EOL;
            continue;
        }
        $cost = $service->createMonthlyCharge($buildingId, (int) $row['created_by']);
        $created++;
        echo "building #{$buildingId} ({$row['name']}): monthly charge #{$cost->id} created" . PHP_EOL;
    } catch (Throwable $e) {
        echo "building #{$buildingId}: FAILED: " . $e->getMessage() . PHP_EOL;
    }
}
echo "Done. {$created} monthly charge(s) created." . PHP_EOL;
