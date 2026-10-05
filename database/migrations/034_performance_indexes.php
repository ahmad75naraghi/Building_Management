<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * مایگریشن ۰۳۴ — ایندکس‌های کارایی برای مقیاس بزرگ
 *
 * کوئری‌های داغ سامانه (لیست هزینه‌ها/پرداخت‌ها، مانده واحدها، اعضای ساختمان،
 * اعلانات و ماژول‌های عملیاتی) روی کلیدهای تکراری فیلتر می‌شوند؛
 * این ایندکس‌ها هزینهٔ آن‌ها را از اسکن کامل جدول به جستجوی ایندکسی می‌رساند.
 *
 * همهٔ دستورهای ایجاد ایندکس محافظت‌شده هستند: اگر ایندکسی از قبل وجود داشته
 * باشد یا ستونی در نصب خاصی موجود نباشد، خطا فقط ثبت می‌شود و مایگریشن ادامه می‌یابد.
 */
class Migration_034_performance_indexes
{
    public function up(): void
    {
        $db = Database::getConnection();

        $indexes = [
            // پرداخت‌ها: اتصال به هزینه، فیلتر واحد و کاربر+وضعیت (مانده‌گیری و تأیید)
            "CREATE INDEX idx_cost_payments_cost_id ON cost_payments (cost_id)",
            "CREATE INDEX idx_cost_payments_unit_id ON cost_payments (unit_id)",
            "CREATE INDEX idx_cost_payments_user_status ON cost_payments (user_id, status)",

            // هزینه‌ها: لیست ساختمان + نشانگرهای یکتای شارژ/دوره‌ای
            "CREATE INDEX idx_costs_building_deleted ON costs (building_id, deleted_at)",
            "CREATE INDEX idx_costs_building_description ON costs (building_id, description(100))",
            "CREATE INDEX idx_costs_parent ON costs (parent_cost_id)",

            // عضویت‌ها: نقش کاربر در ساختمان و عضویت‌های یک کاربر
            "CREATE INDEX idx_building_members_building_status ON building_members (building_id, status)",
            "CREATE INDEX idx_building_members_user_status ON building_members (user_id, status)",

            // واحدها: فیلتر ساختمان
            "CREATE INDEX idx_units_building ON units (building_id)",

            // اعلانات: زنگولهٔ کاربر (خوانده/نخوانده)
            "CREATE INDEX idx_notifications_user_read ON notifications (user_id, is_read)",

            // لاگ اقدامات: گزارش ساختمان به ترتیب زمان
            "CREATE INDEX idx_audit_logs_building_created ON audit_logs (building_id, created_at)",

            // ماژول‌های عملیاتی: لیست بر اساس ساختمان
            "CREATE INDEX idx_tickets_building ON tickets (building_id)",
            "CREATE INDEX idx_announcements_building ON announcements (building_id)",
            "CREATE INDEX idx_meetings_building ON meetings (building_id)",
            "CREATE INDEX idx_bookings_building_date ON bookings (building_id, booking_date)",
            "CREATE INDEX idx_visitors_building ON visitors (building_id)",
            "CREATE INDEX idx_maintenance_building ON maintenance_requests (building_id)",
            "CREATE INDEX idx_votes_building ON votes (building_id)",
            "CREATE INDEX idx_reviews_building ON reviews (building_id)",
            "CREATE INDEX idx_documents_building ON documents (building_id)",
            "CREATE INDEX idx_consumption_building_date ON consumption_readings (building_id, reading_date)",
            "CREATE INDEX idx_invitations_status ON invitations (status)",
            "CREATE INDEX idx_penalty_settings_building ON penalty_settings (building_id)",
        ];

        foreach ($indexes as $sql) {
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                // ایندکس تکراری یا ستون غیرموجود در نصب‌های قدیمی — مانع ادامه نمی‌شود
                error_log('[034] index skipped: ' . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
    }
}

return (new Migration_034_performance_indexes())->up();
