<?php
/**
 * مدیریت پشتیبان‌های پایگاه‌داده — فقط شماره‌های تعیین‌شده در BACKUP_ADMIN_PHONES.
 */
require_once 'includes/api_helper.php';

if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header('Location: auth.php');
    exit;
}

// مشخصات کاربر جاری از اندپوینت پروفایل
$me = callAPI('GET', '/auth/me');
$me_data = !empty($me['success']) ? ($me['data'] ?? []) : [];

$backup_service = new \App\Services\BackupService();
$current_user = new \App\Models\User();
$current_user->id = (int) ($me_data['id'] ?? 0);
$current_user->phone = (string) ($me_data['phone'] ?? $me_data['mobile'] ?? '');
$is_backup_admin = $backup_service->isAdmin($current_user);

$alert_message = '';
$alert_type = 'error';

if ($is_backup_admin) {
    // دانلود — پیش از هر خروجی دیگری
    if (($_GET['action'] ?? '') === 'download' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $path = $backup_service->pathFor((string) ($_GET['file'] ?? ''));
        if ($path === null) {
            $alert_message = 'فایل پشتیبان پیدا نشد.';
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . (string) filesize($path));
            header('Cache-Control: no-store');
            readfile($path);
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['form_action'] ?? '';
        if ($action === 'create') {
            try {
                $path = $backup_service->createBackup((int) $current_user->id);
                $backup_service->prune((int) \App\Config\AppConfig::env('BACKUP_KEEP', '7'));
                $alert_message = 'پشتیبان با موفقیت ساخته شد: ' . basename($path);
                $alert_type = 'success';
            } catch (\Throwable $e) {
                $alert_message = 'خطا در ساخت پشتیبان: ' . $e->getMessage();
            }
        } elseif ($action === 'delete') {
            $ok = $backup_service->deleteBackup((string) ($_POST['file'] ?? ''), (int) $current_user->id);
            $alert_message = $ok ? 'پشتیبان حذف شد.' : 'فایل پشتیبان پیدا نشد.';
            $alert_type = $ok ? 'success' : 'error';
        }
    }
}

$backups = $is_backup_admin ? $backup_service->listBackups() : [];

$page_title = 'پشتیبان‌گیری';
$page_hint  = 'پشتیبان کامل پایگاه‌داده؛ فقط برای شماره‌های مجاز (BACKUP_ADMIN_PHONES) فعال است.';
$nav_active = 'none';
require_once 'includes/header.php';
?>

<main class="p-5">

<?php if (!$is_backup_admin): ?>
    <div class="empty-state">
        <div class="empty-icon">🔒</div>
        دسترسی به پشتیبان‌گیری برای این حساب فعال نیست.<br>
        <small style="color:var(--text-gray);">مدیر سیستم می‌تواند شماره‌های مجاز را در متغیر محیطی BACKUP_ADMIN_PHONES تنظیم کند.</small>
    </div>
<?php else: ?>

    <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:14px;">
        <form method="post" style="margin:0; flex:1;" data-confirm-sheet data-sheet-title="ساخت پشتیبان جدید" data-confirm="یک نسخهٔ کامل از پایگاه‌داده در پوشهٔ محافظت‌شدهٔ backups ذخیره می‌شود. ادامه می‌دهید؟">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">
            <button type="submit" class="btn btn-primary" style="width:100%;">🗄️ ساخت پشتیبان جدید</button>
        </form>
    </div>

    <?php if (!$backups): ?>
        <div class="empty-state">
            <div class="empty-icon">🗄️</div>
            هنوز پشتیبانی ساخته نشده است.
        </div>
    <?php else: ?>
        <h2 class="section-title">پشتیبان‌های موجود (<?= fa_digits(count($backups)) ?>)</h2>
        <div class="space-y-3">
            <?php foreach ($backups as $b): ?>
                <div class="card p-4" style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
                    <div style="min-width:0;">
                        <p class="font-bold text-sm" style="word-break:break-all;">📦 <?= htmlspecialchars($b['name'], ENT_QUOTES) ?></p>
                        <p class="text-xs" style="color:var(--text-gray); margin-top:4px;">
                            <?= fa_datetime(date('Y-m-d H:i:s', $b['mtime'])) ?>
                            — <?= fa_digits(number_format($b['size'] / 1024, 1)) ?> کیلوبایت
                        </p>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <a class="btn-chip btn-chip-neutral" style="text-decoration:none;" href="backups.php?action=download&amp;file=<?= htmlspecialchars(urlencode($b['name']), ENT_QUOTES) ?>">⬇️ دانلود</a>
                        <form method="post" style="margin:0;" data-confirm-sheet data-sheet-title="حذف پشتیبان" data-sheet-name="<?= htmlspecialchars($b['name'], ENT_QUOTES) ?>" data-confirm="این فایل پشتیبان برای همیشه حذف می‌شود.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="delete">
                            <input type="hidden" name="file" value="<?= htmlspecialchars($b['name'], ENT_QUOTES) ?>">
                            <button type="submit" class="btn-chip btn-chip-danger">🗑️ حذف</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="hint-card" style="margin-top:14px;">
        ⏱️ پشتیبان‌گیری خودکار: کران روزانه (<code>scripts/cron_daily.php</code>) هر شب یک پشتیبان می‌سازد
        و فقط <?= fa_digits((int) \App\Config\AppConfig::env('BACKUP_KEEP', '7')) ?> نسخهٔ اخیر را نگه می‌دارد.
    </div>

<?php endif; ?>

</main>

<?php require_once 'includes/footer.php'; ?>
