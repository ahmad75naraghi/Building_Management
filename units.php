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

// نقش کاربر — مدیریت واحدها فقط برای مدیر ساختمان
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

// دریافت بلوک‌ها، طبقات و اعضا (برای انتخاب در فرم)
$blocks = [];
$floors = [];
$members = [];
if ($building_id > 0) {
    $blocks_response = callAPI('GET', '/buildings/' . $building_id . '/blocks');
    if (!empty($blocks_response['success'])) {
        $blocks = $blocks_response['data']['blocks'] ?? [];
    }
    $floors_response = callAPI('GET', '/buildings/' . $building_id . '/floors');
    if (!empty($floors_response['success'])) {
        $floors = $floors_response['data']['floors'] ?? [];
    }
    $members_response = callAPI('GET', '/buildings/' . $building_id . '/members');
    if (!empty($members_response['success'])) {
        $members = $members_response['data'] ?? [];
    }
}

// ثبت / ویرایش / حذف واحد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $building_id > 0) {
    $action = $_POST['form_action'] ?? 'create';

    if (!$is_manager) {
        $alert_message = 'فقط مدیر ساختمان می‌تواند واحدها را مدیریت کند.';
    } elseif ($action === 'delete') {
        $unit_id = (int) ($_POST['unit_id'] ?? 0);
        if ($unit_id > 0) {
            $response = callAPI('DELETE', '/units/' . $unit_id);
            if (!empty($response['success'])) {
                $alert_message = 'واحد با موفقیت حذف شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در حذف واحد.';
            }
        }
    } else {
        $unit_number = trim($_POST['unit_number'] ?? '');
        if ($unit_number === '') {
            $alert_message = 'شماره واحد را وارد کنید.';
            $reopen_modal = $action === 'update' ? 'edit-unit' : 'add-unit';
        } else {
            $custom_charge_raw = en_digits($_POST['custom_charge'] ?? '');
            $payload = [
                'unit_number' => en_digits($unit_number),
                'type' => $_POST['type'] ?? 'residential',
                'area' => en_digits($_POST['area'] ?? '') !== '' ? (float) en_digits($_POST['area']) : null,
                'block_id' => !empty($_POST['block_id']) ? (int) $_POST['block_id'] : null,
                'floor_id' => !empty($_POST['floor_id']) ? (int) $_POST['floor_id'] : null,
                'owner_user_id' => !empty($_POST['owner_user_id']) ? (int) $_POST['owner_user_id'] : null,
                'tenant_user_id' => !empty($_POST['tenant_user_id']) ? (int) $_POST['tenant_user_id'] : null,
                'owner_resident' => !empty($_POST['owner_resident']) ? 1 : 0,
                'residents_count' => max(0, (int) en_digits($_POST['residents_count'] ?? '0')),
                'custom_charge' => $custom_charge_raw !== '' ? (float) $custom_charge_raw : null,
                'parking_no' => trim($_POST['parking_no'] ?? '') !== '' ? trim($_POST['parking_no']) : null,
                'storage_no' => trim($_POST['storage_no'] ?? '') !== '' ? trim($_POST['storage_no']) : null,
            ];

            if ($action === 'update') {
                $unit_id = (int) ($_POST['unit_id'] ?? 0);
                if ($unit_id <= 0) {
                    $alert_message = 'شناسه واحد نامعتبر است.';
                } else {
                    $response = callAPI('PUT', '/units/' . $unit_id, $payload);
                    if (!empty($response['success'])) {
                        $alert_message = 'واحد با موفقیت ویرایش شد.';
                        $alert_type = 'success';
                    } else {
                        $alert_message = $response['message'] ?? 'خطا در ویرایش واحد.';
                        $reopen_modal = 'edit-unit';
                    }
                }
            } else {
                $response = callAPI('POST', '/buildings/' . $building_id . '/units', $payload);
                if (!empty($response['success'])) {
                    $alert_message = 'واحد با موفقیت ثبت شد.';
                    $alert_type = 'success';
                } else {
                    $alert_message = $response['message'] ?? 'خطا در ثبت واحد.';
                    $reopen_modal = 'add-unit';
                }
            }
        }
    }
}

// دریافت اطلاعات ساختمان و لیست واحدها
$units = [];
$building = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building = $building_response['data'] ?? [];
        $building_name = $building['name'] ?? '';
    }
    $list_response = callAPI('GET', '/buildings/' . $building_id . '/units');
    if (!empty($list_response['success'])) {
        $units = $list_response['data']['units'] ?? [];
    }
}

$charge_mode = $building['charge_mode'] ?? 'fixed';

$block_names = [];
foreach ($blocks as $b) {
    $block_names[$b['id']] = $b['name'];
}
$floor_names = [];
foreach ($floors as $f) {
    $floor_names[$f['id']] = 'طبقه ' . ($f['floor_number'] ?? '');
}

$occupancy_labels = [
    'owner_occupied' => 'مالک ساکن',
    'tenant_occupied' => 'مستأجر ساکن',
    'vacant' => 'خالی',
    'no_owner' => 'بدون مالک',
];
$occupancy_chips = [
    'owner_occupied' => 'chip-green',
    'tenant_occupied' => 'chip-amber',
    'vacant' => 'chip-gray',
    'no_owner' => 'chip-gray',
];

$page_title = 'مدیریت واحدها';
$header_sub = $building_name ?: 'ساختار مجتمع';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <?php modal_open_button('add-unit', 'افزودن واحد جدید'); ?>

        <?php if ($charge_mode === 'per_person'): ?>
            <div class="hint-card" style="margin-top:12px;">
                👥 شارژ این ساختمان <strong>بر اساس تعداد نفرات</strong> محاسبه می‌شود؛ برای هر واحد تعداد ساکنین را وارد کنید.
            </div>
        <?php elseif ($charge_mode === 'custom'): ?>
            <div class="hint-card" style="margin-top:12px;">
                ✏️ شارژ این ساختمان <strong>دلخواه</strong> است؛ برای هر واحد مبلغ اختصاصی را وارد کنید.
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="hint-card">
            🏠 شما با نقش «<?= htmlspecialchars($ctx['role_label']) ?>» وارد شده‌اید و فقط می‌توانید واحدها را مشاهده کنید.
        </div>
    <?php endif; ?>

    <div class="section-header-row" style="margin: 18px 0 12px;">
        <h2 class="section-title">واحدهای ساختمان (<?= fa_digits(count($units)) ?>)</h2>
    </div>

    <?php if (empty($units)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">🏠</div>
            هنوز واحدی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($units as $unit): ?>
                <?php
                $u_id = (int) ($unit['id'] ?? 0);
                $status = $unit['occupancy_status'] ?? 'no_owner';
                $residents = (int) ($unit['residents_count'] ?? 0);
                ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-bold text-gray-800 text-sm">واحد <?= fa_digits($unit['unit_number'] ?? '—') ?></h3>
                                <span class="chip <?= $occupancy_chips[$status] ?? 'chip-gray' ?>"><?= htmlspecialchars($occupancy_labels[$status] ?? '') ?></span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1.5">
                                <?= htmlspecialchars(unit_type_label($unit['type'] ?? '')) ?>
                                <?php if (!empty($unit['area'])): ?> • <?= fa_number($unit['area']) ?> متر<?php endif; ?>
                                <?php if (!empty($unit['floor_id']) && isset($floor_names[$unit['floor_id']])): ?> • <?= htmlspecialchars($floor_names[$unit['floor_id']]) ?><?php endif; ?>
                                <?php if (!empty($unit['block_id']) && isset($block_names[$unit['block_id']])): ?> • بلوک <?= htmlspecialchars($block_names[$unit['block_id']]) ?><?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div class="building-list-chips" style="margin-top:10px;">
                        <?php if (!empty($unit['owner_name'])): ?>
                            <span class="chip chip-gold">مالک: <?= htmlspecialchars($unit['owner_name']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($unit['tenant_name'])): ?>
                            <span class="chip chip-amber">مستأجر: <?= htmlspecialchars($unit['tenant_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($residents > 0): ?>
                            <span class="chip chip-green"><?= fa_digits($residents) ?> نفر ساکن</span>
                        <?php endif; ?>
                        <?php if (!empty($unit['parking_no'])): ?>
                            <span class="chip chip-gray">🚗 پارکینگ: <?= fa_digits(htmlspecialchars($unit['parking_no'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($unit['storage_no'])): ?>
                            <span class="chip chip-gray">📦 انباری: <?= fa_digits(htmlspecialchars($unit['storage_no'])) ?></span>
                        <?php endif; ?>
                        <?php if ($charge_mode === 'custom' && isset($unit['custom_charge']) && $unit['custom_charge'] !== null): ?>
                            <span class="chip chip-green">شارژ: <?= fa_number($unit['custom_charge']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($is_manager): ?>
                        <div class="card-actions">
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-unit"
                                    data-set-unit_id="<?= $u_id ?>"
                                    data-set-unit_number="<?= htmlspecialchars($unit['unit_number'] ?? '') ?>"
                                    data-set-type="<?= htmlspecialchars($unit['type'] ?? 'residential') ?>"
                                    data-set-area="<?= htmlspecialchars((string) ($unit['area'] ?? '')) ?>"
                                    data-set-block_id="<?= (int) ($unit['block_id'] ?? 0) ?>"
                                    data-set-floor_id="<?= (int) ($unit['floor_id'] ?? 0) ?>"
                                    data-set-owner_user_id="<?= (int) ($unit['owner_user_id'] ?? 0) ?>"
                                    data-set-tenant_user_id="<?= (int) ($unit['tenant_user_id'] ?? 0) ?>"
                                    data-set-owner_resident="<?= !empty($unit['owner_resident']) ? '1' : '0' ?>"
                                    data-set-residents_count="<?= $residents ?>"
                                    data-set-custom_charge="<?= htmlspecialchars((string) ($unit['custom_charge'] ?? '')) ?>"
                                    data-set-parking_no="<?= htmlspecialchars($unit['parking_no'] ?? '') ?>"
                                    data-set-storage_no="<?= htmlspecialchars($unit['storage_no'] ?? '') ?>">
                                ویرایش
                            </button>
                            <form method="POST" action="?building_id=<?= $building_id ?>" data-confirm="واحد حذف شود؟ این عمل قابل بازگشت نیست." style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="unit_id" value="<?= $u_id ?>">
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

    <?php modal_start('add-unit', 'افزودن واحد جدید', 'مشخصات واحد و ساکنین'); ?>
        <form method="POST" action="?building_id=<?= $building_id ?>" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">
            <?php include 'includes/_unit_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره واحد</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-unit', 'ویرایش واحد', 'مشخصات، ساکنین و شارژ'); ?>
        <form method="POST" action="?building_id=<?= $building_id ?>" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="unit_id" value="">
            <?php include 'includes/_unit_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>

    <script>
        /* اگر مستأجر انتخاب شود، «مالک ساکن است» معنا ندارد (ساکن، مستأجر است) */
        document.querySelectorAll('form').forEach(function (form) {
            var tenant = form.querySelector('[name="tenant_user_id"]');
            var ownerResident = form.querySelector('[name="owner_resident"]');
            if (!tenant || !ownerResident) {
                return;
            }
            function sync() {
                var hasTenant = tenant.value !== '';
                if (hasTenant) {
                    ownerResident.checked = false;
                }
                ownerResident.disabled = hasTenant;
                var wrap = ownerResident.closest('label');
                if (wrap) {
                    wrap.style.opacity = hasTenant ? '0.5' : '1';
                }
            }
            tenant.addEventListener('change', sync);
            form.addEventListener('reset', function () { setTimeout(sync, 0); });
            sync();
        });
    </script>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
