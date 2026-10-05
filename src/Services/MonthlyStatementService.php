<?php
/**
 * صورت‌حساب ماهانهٔ واحدها — ارسال خلاصهٔ مالی هر واحد برای مالک/مستأجر.
 *
 * برای هر واحد، جمع مبالغ صادرشده و پرداخت‌های تأییدشده محاسبه شده و به‌صورت
 * اعلان درون‌اپی (+ وب‌پوش خودکار توسط NotificationService) ارسال می‌شود.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Logger;
use App\Utilities\JalaliHelper;

final class MonthlyStatementService
{
    public function __construct(
        private NotificationService $notifications = new NotificationService(),
    ) {
    }

    /**
     * @return array{units:int, sent:int, skipped:int}
     */
    public function send(int $buildingId, int $managerId): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT cp.unit_id,
                    un.unit_number,
                    un.owner_user_id,
                    un.tenant_user_id,
                    SUM(cp.share_amount) AS billed,
                    SUM(CASE WHEN cp.status = 'confirmed' THEN COALESCE(cp.amount_paid, 0) ELSE 0 END) AS paid
             FROM cost_payments cp
             INNER JOIN costs c ON c.id = cp.cost_id AND c.deleted_at IS NULL
             LEFT JOIN units un ON un.id = cp.unit_id
             WHERE c.building_id = ? AND cp.unit_id IS NOT NULL
             GROUP BY cp.unit_id"
        );
        $stmt->execute([$buildingId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        [$jy, $jm] = JalaliHelper::toJalali((int) date('Y'), (int) date('n'), (int) date('j'));
        $monthLabel = JalaliHelper::MONTH_NAMES[$jm] . ' ' . JalaliHelper::faDigits((string) $jy);

        $result = ['units' => count($rows), 'sent' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $recipient = (int) ($row['owner_user_id'] ?? 0) ?: (int) ($row['tenant_user_id'] ?? 0);
            if ($recipient <= 0) {
                $result['skipped']++;
                continue;
            }

            $billed = (float) ($row['billed'] ?? 0);
            $paid = (float) ($row['paid'] ?? 0);
            $balance = $billed - $paid;
            $unitLabel = 'واحد ' . ($row['unit_number'] ?? $row['unit_id']);

            $message = $unitLabel . ' — صادرشده: ' . JalaliHelper::faDigits(number_format($billed))
                . ' | پرداخت‌شده: ' . JalaliHelper::faDigits(number_format($paid))
                . ' | مانده: ' . JalaliHelper::faDigits(number_format($balance)) . ' تومان.'
                . ($balance > 0 ? ' لطفاً نسبت به تسویه اقدام فرمایید.' : ' حساب شما تسویه است؛ سپاس 🌟');

            try {
                $this->notifications->createNotification([
                    'user_id' => $recipient,
                    'building_id' => $buildingId,
                    'notification_type' => 'payment',
                    'title' => '📄 صورت‌حساب ' . $monthLabel . ' — ' . $unitLabel,
                    'message' => $message,
                    'data' => ['unit_id' => (int) $row['unit_id']],
                ]);
                $result['sent']++;
            } catch (\Throwable $e) {
                $result['skipped']++;
                Logger::error('MonthlyStatement', 'صورت‌حساب ارسال نشد', [
                    'unit_id' => (int) $row['unit_id'],
                    'user_id' => $recipient,
                ], $e);
            }
        }

        Audit::log($managerId, 'statement.send_monthly', 'building', $buildingId, $buildingId, $result);

        return $result;
    }
}
