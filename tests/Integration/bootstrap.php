<?php
/**
 * بوت‌استرپ تست‌های یکپارچه.
 *
 * یک پایگاه‌داده SQLite در حافظه می‌سازد، اسکیمای معادل MySQL را روی آن اجرا می‌کند
 * و اتصال را از طریق Reflection داخل App\Core\Database تزریق می‌کند تا سرویس‌های
 * واقعی برنامه بدون تغییر کد اجرا شوند.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// ---------------------------------------------------------------- گزارش‌گیر
final class TestLog
{
    /** @var array<int,array{suite:string,name:string,ok:bool,msg:string}> */
    public static array $results = [];
    public static string $suite = 'general';
    private static float $start = 0.0;

    public static function suite(string $name): void
    {
        self::$suite = $name;
        echo "\n\033[1;36m▍ {$name}\033[0m\n";
    }

    public static function start(): void
    {
        self::$start = microtime(true);
    }

    public static function ok(string $name, string $msg = ''): void
    {
        self::$results[] = ['suite' => self::$suite, 'name' => $name, 'ok' => true, 'msg' => $msg];
        echo "  \033[32m✔\033[0m {$name}" . ($msg !== '' ? " \033[90m({$msg})\033[0m" : '') . "\n";
    }

    public static function fail(string $name, string $msg = ''): void
    {
        self::$results[] = ['suite' => self::$suite, 'name' => $name, 'ok' => false, 'msg' => $msg];
        echo "  \033[31m✘ {$name}\033[0m\n";
        if ($msg !== '') {
            foreach (explode("\n", $msg) as $line) {
                echo "      \033[31m{$line}\033[0m\n";
            }
        }
    }

    /** اجرای یک تست با گرفتن استثناهای پیش‌بینی‌نشده */
    public static function run(string $name, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            self::fail($name, get_class($e) . ': ' . $e->getMessage()
                . "\n  at " . str_replace(dirname(__DIR__, 2) . '/', '', $e->getFile()) . ':' . $e->getLine());
        }
    }

    public static function assertTrue(string $name, $cond, string $detail = ''): void
    {
        $cond ? self::ok($name, $detail) : self::fail($name, $detail !== '' ? $detail : 'انتظار true بود');
    }

    public static function assertSame(string $name, $expected, $actual): void
    {
        if ($expected === $actual) {
            self::ok($name);
        } else {
            self::fail($name, 'انتظار: ' . var_export($expected, true) . ' | دریافت: ' . var_export($actual, true));
        }
    }

    public static function assertThrows(string $name, callable $fn, ?string $needle = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($needle === null || str_contains($e->getMessage(), $needle)) {
                self::ok($name, mb_substr($e->getMessage(), 0, 60));
                return;
            }
            self::fail($name, "پیام خطای نامنتظره: {$e->getMessage()}");
            return;
        }
        self::fail($name, 'انتظار پرتاب استثنا بود ولی پرتاب نشد');
    }

    public static function summary(): int
    {
        $total = count(self::$results);
        $failed = array_values(array_filter(self::$results, fn($r) => !$r['ok']));
        $dur = number_format((microtime(true) - self::$start) * 1000, 0);

        echo "\n" . str_repeat('─', 62) . "\n";
        if (!$failed) {
            echo "\033[1;32m✔ همه {$total} تست با موفقیت گذشتند\033[0m ({$dur}ms)\n";
            return 0;
        }
        echo "\033[1;31m✘ " . count($failed) . " از {$total} تست شکست خورد\033[0m ({$dur}ms)\n\n";
        foreach ($failed as $f) {
            echo "  \033[31m•\033[0m [{$f['suite']}] {$f['name']}\n";
            if ($f['msg'] !== '') {
                echo "    \033[90m{$f['msg']}\033[0m\n";
            }
        }
        return 1;
    }
}

// ---------------------------------------------------------------- پایگاه‌داده
/**
 * ساخت اسکیمای تست روی SQLite (معادل ساده‌شدهٔ all_migrations.sql).
 */
function test_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT DEFAULT NULL,
        email TEXT DEFAULT NULL,
        phone TEXT DEFAULT NULL UNIQUE,
        password_hash TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE otp_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone TEXT NOT NULL,
        code_hash TEXT NOT NULL,
        purpose TEXT NOT NULL DEFAULT 'auth',
        attempts INTEGER NOT NULL DEFAULT 0,
        consumed_at TEXT DEFAULT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE buildings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        address TEXT,
        custom_name TEXT,
        theme_color TEXT,
        created_by INTEGER,
        total_units INTEGER DEFAULT NULL,
        total_floors INTEGER DEFAULT NULL,
        has_blocks INTEGER DEFAULT 0,
        default_image TEXT DEFAULT 'b1',
        parking_spots INTEGER DEFAULT 0,
        monthly_charge REAL DEFAULT 0,
        monthly_charge_enabled INTEGER DEFAULT 0,
        charge_mode TEXT DEFAULT 'fixed',
        charge_per_person REAL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE building_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        role TEXT NOT NULL DEFAULT 'resident',
        status TEXT NOT NULL DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE blocks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL,
        name TEXT,
        description TEXT
    )");

    $pdo->exec("CREATE TABLE floors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL,
        block_id INTEGER DEFAULT NULL,
        floor_number TEXT,
        name TEXT
    )");

    $pdo->exec("CREATE TABLE common_areas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        type TEXT,
        description TEXT,
        bookable INTEGER DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE units (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL,
        block_id INTEGER DEFAULT NULL,
        floor_id INTEGER DEFAULT NULL,
        unit_number TEXT NOT NULL,
        type TEXT DEFAULT 'residential',
        area REAL DEFAULT NULL,
        owner_user_id INTEGER DEFAULT NULL,
        tenant_user_id INTEGER DEFAULT NULL,
        owner_resident INTEGER DEFAULT 0,
        residents_count INTEGER DEFAULT 0,
        custom_charge REAL DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE costs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT,
        amount REAL NOT NULL,
        cost_type TEXT DEFAULT 'periodic',
        target_audience TEXT DEFAULT 'all',
        division_method TEXT DEFAULT 'fixed_share',
        division_details TEXT,
        target_unit_ids TEXT DEFAULT NULL,
        due_date TEXT DEFAULT NULL,
        status TEXT DEFAULT 'pending',
        is_recurring INTEGER DEFAULT 0,
        recurring_interval TEXT DEFAULT NULL,
        created_by INTEGER,
        issued_at TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE cost_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cost_id INTEGER NOT NULL,
        unit_id INTEGER DEFAULT NULL,
        user_id INTEGER DEFAULT NULL,
        amount REAL DEFAULT NULL,
        amount_paid REAL DEFAULT NULL,
        share_amount REAL DEFAULT NULL,
        receipt_path TEXT DEFAULT NULL,
        receipt_is_public INTEGER DEFAULT 0,
        notes TEXT DEFAULT NULL,
        status TEXT DEFAULT 'pending',
        payment_date TEXT DEFAULT NULL,
        reject_reason TEXT DEFAULT NULL,
        confirmed_by INTEGER DEFAULT NULL,
        confirmed_at TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT NULL,
        action TEXT NOT NULL,
        entity_type TEXT DEFAULT NULL,
        entity_id INTEGER DEFAULT NULL,
        building_id INTEGER DEFAULT NULL,
        meta TEXT DEFAULT NULL,
        ip TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE votes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT DEFAULT NULL,
        start_date TEXT DEFAULT CURRENT_TIMESTAMP,
        end_date TEXT DEFAULT NULL,
        status TEXT DEFAULT 'active',
        created_by INTEGER NOT NULL
    )");

    $pdo->exec("CREATE TABLE vote_options (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vote_id INTEGER NOT NULL,
        option_text TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE vote_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vote_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        option_id INTEGER NOT NULL,
        voted_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (vote_id, user_id)
    )");

    $pdo->exec("CREATE TABLE review_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        category_id INTEGER DEFAULT NULL,
        rating INTEGER DEFAULT 5,
        review_text TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        building_id INTEGER DEFAULT NULL,
        notification_type TEXT DEFAULT 'general',
        title TEXT NOT NULL,
        message TEXT DEFAULT NULL,
        data TEXT DEFAULT NULL,
        is_read INTEGER DEFAULT 0,
        is_email_sent INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        read_at TEXT DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE penalty_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL,
        penalty_type TEXT DEFAULT 'percentage',
        penalty_value REAL NOT NULL,
        delay_days INTEGER DEFAULT 1,
        applies_to TEXT DEFAULT 'unconfirmed_payments',
        is_active INTEGER DEFAULT 1,
        created_by INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE penalties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cost_payment_id INTEGER NOT NULL,
        building_id INTEGER NOT NULL,
        penalty_amount REAL NOT NULL,
        applied_at TEXT DEFAULT CURRENT_TIMESTAMP,
        reason TEXT DEFAULT NULL,
        created_by INTEGER DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        building_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        file_path TEXT NOT NULL,
        document_type TEXT DEFAULT 'general',
        uploaded_by INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        stored_name TEXT DEFAULT NULL,
        mime_type TEXT DEFAULT NULL,
        file_size INTEGER DEFAULT NULL,
        is_visible_to_members INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT DEFAULT NULL,
        FOREIGN KEY (building_id) REFERENCES buildings(id),
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
    )");

    // تزریق اتصال به Database از طریق Reflection
    $ref = new ReflectionClass(\App\Core\Database::class);
    $prop = $ref->getProperty('connection');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);

    return $pdo;
}

/** پاک‌سازی جدول‌ها بین سوئیت‌ها */
function test_db_reset(): void
{
    $db = test_db();
    foreach (['reviews','vote_results','vote_options','votes','audit_logs','documents','cost_payments','costs','units','common_areas','floors','blocks',
              'building_members','buildings','otp_codes','users'] as $t) {
        $db->exec("DELETE FROM {$t}");
        $db->exec("DELETE FROM sqlite_sequence WHERE name = '{$t}'");
    }
}

/** ساخت کاربر آزمایشی */
function make_user(string $phone, ?string $name = 'کاربر تست', ?string $password = 'secret123'): int
{
    $db = test_db();
    $stmt = $db->prepare("INSERT INTO users (name, phone, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$name, $phone, $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null]);
    return (int) $db->lastInsertId();
}

/** ساخت ساختمان با مدیر مشخص */
function make_building(int $managerId, array $overrides = []): int
{
    $db = test_db();
    $cols = array_merge([
        'name' => 'ساختمان تست',
        'address' => 'تهران',
        'created_by' => $managerId,
        'monthly_charge' => 0,
        'monthly_charge_enabled' => 1,
        'charge_mode' => 'fixed',
        'charge_per_person' => 0,
    ], $overrides);

    $keys = array_keys($cols);
    $stmt = $db->prepare(
        "INSERT INTO buildings (" . implode(',', $keys) . ") VALUES (" . implode(',', array_fill(0, count($keys), '?')) . ")"
    );
    $stmt->execute(array_values($cols));
    $bid = (int) $db->lastInsertId();

    $db->prepare("INSERT INTO building_members (building_id, user_id, role, status) VALUES (?, ?, 'manager', 'active')")
       ->execute([$bid, $managerId]);
    return $bid;
}

/** افزودن عضو با نقش دلخواه */
function add_member(int $buildingId, int $userId, string $role = 'tenant'): void
{
    test_db()->prepare("INSERT INTO building_members (building_id, user_id, role, status) VALUES (?, ?, ?, 'active')")
             ->execute([$buildingId, $userId, $role]);
}

/** ساخت واحد */
function make_unit(int $buildingId, string $number, array $overrides = []): int
{
    $db = test_db();
    $cols = array_merge([
        'building_id' => $buildingId,
        'unit_number' => $number,
        'type' => 'residential',
        'residents_count' => 0,
        'custom_charge' => null,
    ], $overrides);
    $keys = array_keys($cols);
    $stmt = $db->prepare(
        "INSERT INTO units (" . implode(',', $keys) . ") VALUES (" . implode(',', array_fill(0, count($keys), '?')) . ")"
    );
    $stmt->execute(array_values($cols));
    return (int) $db->lastInsertId();
}
