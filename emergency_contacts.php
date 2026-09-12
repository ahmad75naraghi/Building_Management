<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: auth.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

$alert_message = '';
$alert_type = 'error';
$reopen_modal = '';

// مخاطبین اضطراری و ارسال هشدار فقط در اختیار مدیر ساختمان است.
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

$role_labels = [
    'fire' => 'آتش‌نشانی',
    'police' => 'پلیس',
    'medical' => 'اورژانس',
    'gas' => 'گاز',
    'electricity' => 'برق',
    'water' => 'آب',
    'manager' => 'مدیر ساختمان',
    'other' => 'سایر',
];

$alert_type_labels = [
    'general' => 'عمومی',
    'fire' => 'آتش‌سوزی',
    'security' => 'امنیتی',
    'medical' => 'اورژانس پزشکی',
    'water' => 'نشت آب',
    'gas' => 'نشت گاز',
    'elevator' => 'آسانسور',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند مخاطبین اضطراری را مدیریت کند.';
    } elseif ($action === 'send_alert') {
        $message = trim($_POST['alert_message'] ?? '');
        if ($message === '') {
            $alert_message = 'متن هشدار را وارد کنید.';
            $reopen_modal = 'send-alert';
        } else {
            $response = callAPI('POST', '/emergency-alerts', [
                'building_id' => $building_id,
                'alert_type' => $_POST['alert_type_value'] ?? 'general',
                'message' => $message,
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'هشدار اضطراری برای ساکنین ارسال شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ارسال هشدار.';
                $reopen_modal = 'send-alert';
            }
        }
    } elseif ($action === 'delete') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        $response = callAPI('DELETE', '/emergency-contacts/' . $item_id);
        if (!empty($response['success'])) {
            $alert_message = 'مخاطب حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف مخاطب.';
        }
    } else {
        $name = trim($_POST['contact_name'] ?? '');
        $phone = en_digits(trim($_POST['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            $alert_message = 'نام و شماره تماس الزامی است.';
            $reopen_modal = $action === 'update' ? 'edit-contact' : 'add-contact';
        } else {
            $payload = [
                'contact_name' => $name,
                'phone' => $phone,
                'contact_role' => trim($_POST['contact_role'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
            ];
            if ($action === 'update') {
                $item_id = (int) ($_POST['item_id'] ?? 0);
                $response = callAPI('PUT', '/emergency-contacts/' . $item_id, $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'مخاطب ویرایش شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ویرایش مخاطب.';
                    $reopen_modal = 'edit-contact';
                }
            } else {
                $payload['building_id'] = $building_id;
                $response = callAPI('POST', '/emergency-contacts', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'مخاطب اضطراری ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت مخاطب.';
                    $reopen_modal = 'add-contact';
                }
            }
        }
    }
}

// دریافت لیست مخاطبین و هشدارها
$contacts = [];
$alerts = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $list_response = callAPI('GET', '/emergency-contacts', ['building_id' => $building_id]);
    if (!empty($list_response['success'])) {
        $contacts = $list_response['data'] ?? [];
    }
    $alerts_response = callAPI('GET', '/emergency-alerts', ['building_id' => $building_id]);
    if (!empty($alerts_response['success'])) {
        $alerts = $alerts_response['data'] ?? [];
    }
}

$page_title = 'مخاطبین اضطراری';
$header_sub = $building_name ?: 'شماره‌های مهم';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <div class="flex gap-2">
            <?php modal_open_button('add-contact', 'افزودن مخاطب'); ?>
        </div>
        <button type="button" class="btn-danger-soft" data-modal-open="send-alert" style="margin-top:10px;">
            🚨 ارسال هشدار اضطراری به ساکنین
        </button>
    <?php else: ?>
        <div class="hint-card">🆘 شماره‌های اضطراری زیر توسط مدیر ساختمان ثبت شده‌اند. برای تماس روی شماره بزنید.</div>
    <?php endif; ?>

    <?php if (!empty($alerts)): ?>
        <div class="section-header-row" style="margin: 20px 0 12px;">
            <h2 class="section-title">هشدارهای اخیر</h2>
        </div>
        <div class="space-y-3">
            <?php foreach (array_slice($alerts, 0, 5) as $a): ?>
                <div class="card p-4" style="border-right:4px solid #ef4444;">
                    <div class="flex items-center justify-between gap-2">
                        <span class="chip chip-red">
                            <?= htmlspecialchars($alert_type_labels[$a['alert_type'] ?? ''] ?? 'عمومی') ?>
                        </span>
                        <span class="text-[11px] text-gray-400"><?= fa_time_ago($a['created_at'] ?? '') ?></span>
                    </div>
                    <p class="text-sm text-gray-700 mt-2 leading-6"><?= nl2br(htmlspecialchars($a['message'] ?? '')) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 20px 0 12px;">
        <h2 class="section-title">شماره‌های اضطراری (<?= fa_digits(count($contacts)) ?>)</h2>
    </div>

    <?php if (empty($contacts)): ?>
        <div class="empty-state">
            <div class="empty-icon">🆘</div>
            مخاطبی ثبت نشده است.
            <?php if ($is_manager): ?>
                <button type="button" class="empty-action" data-modal-open="add-contact">➕ افزودن مخاطب اضطراری</button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($contacts as $contact): ?>
                <?php
                $c_id = (int) ($contact['id'] ?? 0);
                $c_name = $contact['contact_name'] ?? 'بدون نام';
                $c_role = $contact['contact_role'] ?? '';
                $c_phone = $contact['phone'] ?? '';
                $c_email = $contact['email'] ?? '';
                ?>
                <div class="card p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-red-50 text-red-600 flex items-center justify-center flex-shrink-0" style="font-size:19px;">☎️</div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($c_name) ?></h3>
                            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($role_labels[$c_role] ?? $c_role) ?></p>
                        </div>
                        <a href="tel:<?= htmlspecialchars($c_phone) ?>" class="btn-chip btn-chip-success flex-shrink-0" dir="ltr">
                            <?= fa_digits($c_phone) ?>
                        </a>
                    </div>

                    <?php if ($is_manager): ?>
                        <div class="card-actions">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-contact"
                                    data-set-item_id="<?= $c_id ?>"
                                    data-set-contact_name="<?= htmlspecialchars($c_name) ?>"
                                    data-set-contact_role="<?= htmlspecialchars($c_role) ?>"
                                    data-set-phone="<?= htmlspecialchars($c_phone) ?>"
                                    data-set-email="<?= htmlspecialchars($c_email) ?>">
                                ویرایش
                            </button>
                            <form method="POST" action="" data-confirm="این مخاطب حذف شود؟" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="item_id" value="<?= $c_id ?>">
                                <button type="submit" class="btn-chip btn-chip-danger">حذف</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php if ($is_manager): ?>
    <?php modal_start('add-contact', 'افزودن مخاطب اضطراری', 'نام، نقش و شماره تماس'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">
            <?php include 'includes/_contact_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ثبت مخاطب</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-contact', 'ویرایش مخاطب', 'اصلاح اطلاعات تماس'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="item_id" value="">
            <?php include 'includes/_contact_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('send-alert', 'ارسال هشدار اضطراری', 'پیام برای همه ساکنین ارسال می‌شود'); ?>
        <form method="POST" action="" class="space-y-4" data-confirm="هشدار اضطراری برای همه ساکنین ارسال شود؟" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="send_alert">
            <div>
                <label class="form-label">نوع هشدار</label>
                <select name="alert_type_value" class="form-input">
                    <?php foreach ($alert_type_labels as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">متن هشدار *</label>
                <textarea name="alert_message" rows="3" required class="form-input" placeholder="مثال: نشت گاز در پارکینگ — لطفاً ساختمان را ترک کنید"></textarea>
            </div>
            <button type="submit" class="btn-danger">ارسال هشدار</button>
        </form>
    <?php modal_end(); ?>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
