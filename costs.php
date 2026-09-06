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

// نقش کاربر جاری در این ساختمان
$ctx = building_role_context($building_id);
$current_user_id = $ctx['user_id'];
$is_manager = $ctx['is_manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ---- اقدامات مدیریتی ----
    $manager_actions = ['create_cost', 'update_cost', 'delete_cost', 'confirm_payment', 'create_monthly', 'save_charge_settings', 'create_penalty_setting'];
    if (in_array($action, $manager_actions, true) && !$is_manager) {
        $alert_message = 'این عملیات فقط برای مدیر ساختمان مجاز است.';
    } elseif ($action === 'save_charge_settings') {
        // تنظیمات شارژ ماهیانه: ثابت / نفری / دلخواه
        $charge_mode = in_array(($_POST['charge_mode'] ?? 'fixed'), ['fixed', 'per_person', 'custom'], true)
            ? $_POST['charge_mode'] : 'fixed';
        $payload = [
            'charge_mode' => $charge_mode,
            'monthly_charge' => max(0, (float) en_digits($_POST['monthly_charge'] ?? 0)),
            'charge_per_person' => max(0, (float) en_digits($_POST['charge_per_person'] ?? 0)),
            'monthly_charge_enabled' => !empty($_POST['monthly_charge_enabled']),
        ];
        $response = callAPI('PUT', '/buildings/' . $building_id, $payload);
        if (!empty($response['success'])) {
            $alert_message = 'تنظیمات شارژ ماهیانه ذخیره شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ذخیره تنظیمات شارژ.';
            $reopen_modal = 'charge-settings';
        }
    } elseif ($action === 'create_monthly') {
        $response = callAPI('POST', '/costs/monthly-charge', ['building_id' => $building_id]);
        if (!empty($response['success'])) {
            $alert_message = 'شارژ ماه جاری ثبت شد و به بدهکاری واحدها اضافه گردید.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت شارژ ماهیانه.';
        }
    } elseif ($action === 'create_cost' || $action === 'update_cost') {
        $title = trim($_POST['title'] ?? '');
        $amount_raw = en_digits($_POST['amount'] ?? '');
        if ($title === '' || $amount_raw === '' || (float) $amount_raw <= 0) {
            $alert_message = 'عنوان و مبلغ هزینه را به‌درستی وارد کنید.';
            $reopen_modal = $action === 'update_cost' ? 'edit-cost' : 'add-cost';
        } else {
            $payload = [
                'title' => $title,
                'description' => trim($_POST['description'] ?? ''),
                'amount' => (float) $amount_raw,
                'cost_type' => $_POST['cost_type'] ?? 'periodic',
                'division_method' => $_POST['division_method'] ?? 'fixed_share',
                'due_date' => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
                'target_audience' => $_POST['target_audience'] ?? 'all',
            ];
            if ($action === 'update_cost') {
                $cost_id = (int) ($_POST['cost_id'] ?? 0);
                $response = callAPI('PUT', '/costs/' . $cost_id, $payload);
                $ok_msg = 'هزینه ویرایش شد.';
                $err_modal = 'edit-cost';
            } else {
                $payload['building_id'] = $building_id;
                $response = callAPI('POST', '/costs', $payload);
                $ok_msg = 'هزینه با موفقیت ثبت شد.';
                $err_modal = 'add-cost';
            }
            if (!empty($response['success'])) {
                $alert_message = $ok_msg;
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت هزینه.';
                $reopen_modal = $err_modal;
            }
        }
    } elseif ($action === 'delete_cost') {
        $cost_id = (int) ($_POST['cost_id'] ?? 0);
        $response = callAPI('DELETE', '/costs/' . $cost_id);
        if (!empty($response['success'])) {
            $alert_message = 'هزینه حذف شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در حذف هزینه.';
        }
    } elseif ($action === 'confirm_payment') {
        $payment_id = (int) ($_POST['payment_id'] ?? 0);
        if ($payment_id > 0) {
            $response = callAPI('POST', '/payments/' . $payment_id . '/confirm', ['status' => 'confirmed']);
            if (!empty($response['success'])) {
                $alert_message = 'پرداخت با موفقیت تأیید شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در تأیید پرداخت.';
            }
        }
    } elseif ($action === 'submit_payment') {
        // پرداخت توسط ساکن/مالک/مستأجر
        $cost_id = (int) ($_POST['cost_id'] ?? 0);
        if ($cost_id > 0) {
            $amount_paid = en_digits($_POST['amount_paid'] ?? '');
            $response = callAPI('POST', '/payments/submit', [
                'cost_id' => $cost_id,
                'amount_paid' => $amount_paid !== '' ? (float) $amount_paid : null,
                'notes' => trim($_POST['notes'] ?? ''),
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'پرداخت شما ثبت شد. حالا رسید را آپلود کنید.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت پرداخت.';
                $reopen_modal = 'pay-cost';
            }
        }
    } elseif ($action === 'upload_receipt') {
        $payment_id = (int) ($_POST['payment_id'] ?? 0);
        if ($payment_id > 0 && isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $fields = ['is_public' => isset($_POST['is_public']) ? '1' : '0'];
            $files = ['receipt' => $_FILES['receipt']['tmp_name']];
            $response = callAPIUpload('/payments/' . $payment_id . '/upload-receipt', $fields, $files);
            if (!empty($response['success'])) {
                $alert_message = 'رسید با موفقیت آپلود شد. در انتظار تأیید مدیر.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در آپلود رسید.';
            }
        } else {
            $alert_message = 'لطفاً فایل رسید را انتخاب کنید.';
        }
    } elseif ($action === 'create_penalty_setting') {
        $penalty_type = ($_POST['penalty_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
        $penalty_value = en_digits($_POST['penalty_value'] ?? '');
        $delay_days = (int) en_digits($_POST['delay_days'] ?? '1');
        if ($penalty_value === '' || (float) $penalty_value < 0 || $delay_days < 0) {
            $alert_message = 'مقدار جریمه و روز تأخیر را به‌درستی وارد کنید.';
            $reopen_modal = 'penalty-settings';
        } else {
            $response = callAPI('POST', '/penalty-settings', [
                'building_id' => $building_id,
                'type' => $penalty_type,
                'amount' => (float) $penalty_value,
                'delay_days' => $delay_days,
                'applies_to' => 'unconfirmed_payments',
                'is_active' => true,
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'تنظیم جریمه دیرکرد ذخیره شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت تنظیم جریمه.';
                $reopen_modal = 'penalty-settings';
            }
        }
    }
}

// ---------- دریافت اطلاعات ----------
$financial = [];
$costs = [];
$payments = [];
$building = [];
$building_name = '';
$charge_preview = ['mode' => 'fixed', 'total' => 0, 'units' => []];

if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building = $building_response['data'] ?? [];
        $building_name = $building['name'] ?? '';
    }
    $summary_response = callAPI('GET', '/costs/summary', ['building_id' => $building_id]);
    if (!empty($summary_response['success'])) {
        $financial = $summary_response['data'] ?? [];
    }
    $costs_response = callAPI('GET', '/costs', ['building_id' => $building_id]);
    if (!empty($costs_response['success'])) {
        $costs = $costs_response['data'] ?? [];
    }
    $payments_response = callAPI('GET', '/payments', ['building_id' => $building_id]);
    if (!empty($payments_response['success'])) {
        $payments = $payments_response['data'] ?? [];
    }
    if ($is_manager) {
        $preview_response = callAPI('GET', '/costs/charge-preview', ['building_id' => $building_id]);
        if (!empty($preview_response['success'])) {
            $charge_preview = $preview_response['data'] ?? $charge_preview;
        }
    }
}

$charge_mode = $building['charge_mode'] ?? 'fixed';
$charge_mode_labels = [
    'fixed' => 'شارژ ثابت (همه واحدها یکسان)',
    'per_person' => 'بر اساس تعداد نفرات هر واحد',
    'custom' => 'دلخواه برای هر واحد',
];

$division_labels = [
    'fixed_share' => 'سهم ثابت',
    'area' => 'بر اساس متراژ',
    'people_count' => 'بر اساس نفر',
    'custom' => 'دلخواه',
];

$page_title = 'مالی و شارژ';
$header_sub = $building_name ?: 'هزینه‌ها و پرداخت‌ها';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <!-- خلاصه مالی -->
    <div class="finance-summary-card">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold">خلاصه مالی ساختمان</h3>
            <span class="finance-pill"><?= fa_digits((int) round((float) ($financial['collection_percentage'] ?? 0))) ?>٪ وصولی</span>
        </div>
        <div class="finance-stat-grid">
            <div class="finance-stat">
                <p>مجموع هزینه‌ها</p>
                <strong><?= fa_number($financial['total_costs'] ?? 0) ?></strong>
            </div>
            <div class="finance-stat">
                <p>وصول شده</p>
                <strong><?= fa_number($financial['total_collected'] ?? 0) ?></strong>
            </div>
            <div class="finance-stat">
                <p>مانده</p>
                <strong><?= fa_number($financial['total_remaining'] ?? 0) ?></strong>
            </div>
        </div>
        <div class="finance-progress">
            <div class="finance-progress-bar" style="width: <?= max(0, min(100, (int) round((float) ($financial['collection_percentage'] ?? 0)))) ?>%"></div>
        </div>
        <div class="finance-progress-meta">
            <span><?= fa_digits($financial['costs_count'] ?? 0) ?> هزینه</span>
            <span><?= fa_digits($financial['confirmed_count'] ?? 0) ?> پرداخت تأیید شده از <?= fa_digits($financial['payments_count'] ?? 0) ?></span>
        </div>
    </div>

    <?php if ($is_manager): ?>

        <!-- کارت تنظیم شارژ ماهیانه -->
        <div class="card p-4" style="margin-top: 16px;">
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-gray-800 text-sm">شارژ ماهیانه</h3>
                    <p class="text-xs text-gray-500 mt-1">
                        روش فعلی: <span class="font-bold" style="color:var(--gold-primary);"><?= htmlspecialchars($charge_mode_labels[$charge_mode] ?? '') ?></span>
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        <?php if ($charge_mode === 'per_person'): ?>
                            هر نفر: <?= fa_number($building['charge_per_person'] ?? 0) ?> تومان
                        <?php elseif ($charge_mode === 'fixed'): ?>
                            هر واحد: <?= fa_number($building['monthly_charge'] ?? 0) ?> تومان
                        <?php else: ?>
                            مبلغ هر واحد جداگانه در صفحه واحدها تعیین می‌شود.
                        <?php endif; ?>
                    </p>
                    <p class="text-xs mt-2" style="color:<?= !empty($building['monthly_charge_enabled']) ? 'var(--green-success)' : 'var(--text-gray)' ?>;">
                        <?= !empty($building['monthly_charge_enabled']) ? '● فعال — هر ماه خودکار ثبت می‌شود' : '● غیرفعال' ?>
                    </p>
                </div>
                <button type="button" class="btn-chip btn-chip-edit" data-modal-open="charge-settings">تنظیم</button>
            </div>

            <?php if (!empty($charge_preview['units'])): ?>
                <div style="margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;">
                    <p class="text-xs text-gray-500 mb-2">
                        جمع شارژ این ماه: <strong style="color:var(--text-dark);"><?= fa_number($charge_preview['total']) ?> تومان</strong>
                        از <?= fa_digits(count($charge_preview['units'])) ?> واحد
                    </p>
                    <div class="charge-preview-list">
                        <?php foreach (array_slice($charge_preview['units'], 0, 4) as $pu): ?>
                            <div class="charge-preview-row">
                                <span>واحد <?= fa_digits($pu['unit_number']) ?><?= $charge_mode === 'per_person' ? ' (' . fa_digits($pu['residents_count']) . ' نفر)' : '' ?></span>
                                <strong><?= fa_number($pu['amount']) ?></strong>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($charge_preview['units']) > 4): ?>
                            <div class="charge-preview-row" style="color:var(--text-gray);">
                                <span>و <?= fa_digits(count($charge_preview['units']) - 4) ?> واحد دیگر…</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" style="margin-top:12px;">
                <input type="hidden" name="action" value="create_monthly">
                <button type="submit" class="btn-chip btn-chip-success" style="width:100%;justify-content:center;padding:10px;">
                    ثبت شارژ ماه جاری (<?= fa_digits(date('Y-m')) ?>)
                </button>
            </form>
        </div>

        <div style="display:flex;gap:10px;margin-top:14px;">
            <button type="button" class="btn-add-primary" data-modal-open="add-cost" style="flex:1;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                هزینه جدید
            </button>
            <button type="button" class="btn-chip btn-chip-neutral" data-modal-open="penalty-settings" style="padding:14px 16px;border-radius:16px;">
                جریمه دیرکرد
            </button>
        </div>

    <?php else: ?>
        <div class="hint-card" style="margin-top:16px;">
            💳 شما با نقش «<?= htmlspecialchars($ctx['role_label']) ?>» وارد شده‌اید. می‌توانید شارژ خود را پرداخت کرده و رسید آپلود کنید.
        </div>
    <?php endif; ?>

    <!-- لیست هزینه‌ها -->
    <div class="section-header-row" style="margin: 20px 0 12px;">
        <h2 class="section-title">هزینه‌ها و شارژها (<?= fa_digits(count($costs)) ?>)</h2>
    </div>

    <?php if (empty($costs)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">💰</div>
            هنوز هزینه‌ای ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($costs as $cost): ?>
                <?php
                $c_id = (int) ($cost['id'] ?? 0);
                $c_title = $cost['title'] ?? 'بدون عنوان';
                $c_desc = $cost['description'] ?? '';
                // توضیح داخلی شارژ خودکار برای کاربر نمایش داده نشود
                $is_auto = str_starts_with((string) $c_desc, 'auto:monthly:');
                $c_amount = (float) ($cost['amount'] ?? 0);
                ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($c_title) ?></h3>
                            <?php if ($c_desc !== '' && !$is_auto): ?>
                                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($c_desc) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="text-left flex-shrink-0">
                            <p class="font-bold" style="color:var(--blue-info);"><?= fa_number($c_amount) ?> <span class="text-[10px] font-normal text-gray-400">تومان</span></p>
                        </div>
                    </div>

                    <div class="building-list-chips" style="margin-top:10px;">
                        <span class="chip chip-gray"><?= ($cost['cost_type'] ?? '') === 'one_time' ? 'یک‌باره' : 'دوره‌ای' ?></span>
                        <span class="chip chip-gray"><?= htmlspecialchars($division_labels[$cost['division_method'] ?? 'fixed_share'] ?? 'سهم ثابت') ?></span>
                        <?php if ($is_auto): ?>
                            <span class="chip chip-green">شارژ خودکار</span>
                        <?php endif; ?>
                        <?php if (!empty($cost['due_date'])): ?>
                            <span class="chip chip-amber">مهلت: <?= fa_digits($cost['due_date']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="card-actions">
                        <button type="button" class="btn-chip btn-chip-success"
                                data-modal-open="pay-cost"
                                data-set-cost_id="<?= $c_id ?>"
                                data-set-amount_paid="<?= (int) $c_amount ?>">
                            پرداخت
                        </button>

                        <?php if ($is_manager): ?>
                            <button type="button" class="btn-chip btn-chip-edit"
                                    data-modal-open="edit-cost"
                                    data-set-cost_id="<?= $c_id ?>"
                                    data-set-title="<?= htmlspecialchars($c_title) ?>"
                                    data-set-description="<?= $is_auto ? '' : htmlspecialchars($c_desc) ?>"
                                    data-set-amount="<?= (int) $c_amount ?>"
                                    data-set-cost_type="<?= htmlspecialchars($cost['cost_type'] ?? 'periodic') ?>"
                                    data-set-division_method="<?= htmlspecialchars($cost['division_method'] ?? 'fixed_share') ?>"
                                    data-set-target_audience="<?= htmlspecialchars($cost['target_audience'] ?? 'all') ?>"
                                    data-set-due_date="<?= htmlspecialchars($cost['due_date'] ?? '') ?>">
                                ویرایش
                            </button>
                            <form method="POST" action="" data-confirm="این هزینه حذف شود؟" style="display:inline;">
                                <input type="hidden" name="action" value="delete_cost">
                                <input type="hidden" name="cost_id" value="<?= $c_id ?>">
                                <button type="submit" class="btn-chip btn-chip-danger">حذف</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- پرداخت‌ها -->
    <div class="section-header-row" style="margin: 22px 0 12px;">
        <h2 class="section-title"><?= $is_manager ? 'پرداخت‌های ساکنین' : 'پرداخت‌های من' ?></h2>
    </div>

    <?php
    // ساکن فقط پرداخت‌های خودش را می‌بیند
    $visible_payments = $is_manager
        ? $payments
        : array_values(array_filter($payments, static fn($p) => (int) ($p['user_id'] ?? 0) === $current_user_id));
    ?>

    <?php if (empty($visible_payments)): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">🧾</div>
            هنوز پرداختی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($visible_payments as $payment): ?>
                <?php $p_id = (int) ($payment['id'] ?? 0); ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($payment['user_name'] ?? 'کاربر') ?></h3>
                            <p class="text-xs text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($payment['cost_title'] ?? '') ?></p>
                        </div>
                        <div class="text-left flex-shrink-0">
                            <p class="font-bold text-gray-800"><?= fa_number($payment['amount_paid'] ?? 0) ?> <span class="text-[10px] font-normal text-gray-400">تومان</span></p>
                            <span class="chip <?= ($payment['status'] ?? '') === 'confirmed' ? 'chip-green' : 'chip-amber' ?>" style="margin-top:6px;display:inline-block;">
                                <?= htmlspecialchars(payment_status_label($payment['status'] ?? '')) ?>
                            </span>
                        </div>
                    </div>

                    <?php if ((int) ($payment['user_id'] ?? 0) === $current_user_id && ($payment['status'] ?? '') !== 'confirmed'): ?>
                        <form method="POST" action="" enctype="multipart/form-data" class="card-actions" style="flex-wrap:wrap;gap:8px;">
                            <input type="hidden" name="action" value="upload_receipt">
                            <input type="hidden" name="payment_id" value="<?= $p_id ?>">
                            <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf" required
                                   style="flex:1;min-width:140px;font-size:11px;color:var(--text-gray);">
                            <button type="submit" class="btn-chip btn-chip-edit">آپلود رسید</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($is_manager && ($payment['status'] ?? '') !== 'confirmed'): ?>
                        <form method="POST" action="" class="card-actions">
                            <input type="hidden" name="action" value="confirm_payment">
                            <input type="hidden" name="payment_id" value="<?= $p_id ?>">
                            <button type="submit" class="btn-chip btn-chip-success" style="width:100%;justify-content:center;">تأیید پرداخت</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<!-- ==================== پاپ‌آپ‌ها ==================== -->

<?php modal_start('pay-cost', 'پرداخت شارژ', 'پس از ثبت، رسید را آپلود کنید'); ?>
    <form method="POST" action="" class="space-y-4" data-loading>
        <input type="hidden" name="action" value="submit_payment">
        <input type="hidden" name="cost_id" value="">
        <div>
            <label for="pay_amount" class="form-label">مبلغ پرداختی (تومان)</label>
            <input type="number" id="pay_amount" name="amount_paid" min="0" step="1000" inputmode="numeric" class="form-input">
            <p class="text-[11px] text-gray-400 mt-1">اگر خالی بگذارید، کل مبلغ هزینه ثبت می‌شود.</p>
        </div>
        <div>
            <label for="pay_notes" class="form-label">توضیح (اختیاری)</label>
            <textarea id="pay_notes" name="notes" rows="2" class="form-input" placeholder="مثال: پرداخت از طریق کارت به کارت"></textarea>
        </div>
        <button type="submit" class="btn-primary">ثبت پرداخت</button>
    </form>
<?php modal_end(); ?>

<?php if ($is_manager): ?>

    <?php modal_start('charge-settings', 'تنظیم شارژ ماهیانه', 'ثابت، بر اساس نفرات، یا دلخواه'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <input type="hidden" name="action" value="save_charge_settings">

            <div>
                <label class="form-label">روش محاسبه شارژ</label>
                <div class="choice-list">
                    <label class="choice-item">
                        <input type="radio" name="charge_mode" value="fixed" <?= $charge_mode === 'fixed' ? 'checked' : '' ?> data-charge-mode>
                        <span>
                            <strong>شارژ ثابت</strong>
                            <small>همه واحدها ماهانه مبلغ یکسانی می‌پردازند.</small>
                        </span>
                    </label>
                    <label class="choice-item">
                        <input type="radio" name="charge_mode" value="per_person" <?= $charge_mode === 'per_person' ? 'checked' : '' ?> data-charge-mode>
                        <span>
                            <strong>بر اساس تعداد نفرات</strong>
                            <small>مبلغ هر واحد = تعداد ساکنین × نرخ هر نفر.</small>
                        </span>
                    </label>
                    <label class="choice-item">
                        <input type="radio" name="charge_mode" value="custom" <?= $charge_mode === 'custom' ? 'checked' : '' ?> data-charge-mode>
                        <span>
                            <strong>دلخواه برای هر واحد</strong>
                            <small>مبلغ هر واحد را جداگانه در صفحه واحدها تعیین می‌کنید.</small>
                        </span>
                    </label>
                </div>
            </div>

            <div data-charge-field="fixed">
                <label for="monthly_charge" class="form-label">مبلغ ثابت ماهیانه هر واحد (تومان)</label>
                <input type="number" id="monthly_charge" name="monthly_charge" min="0" step="1000" inputmode="numeric" class="form-input"
                       value="<?= htmlspecialchars((string) ($building['monthly_charge'] ?? 0)) ?>">
            </div>

            <div data-charge-field="per_person">
                <label for="charge_per_person" class="form-label">مبلغ به‌ازای هر نفر (تومان)</label>
                <input type="number" id="charge_per_person" name="charge_per_person" min="0" step="1000" inputmode="numeric" class="form-input"
                       value="<?= htmlspecialchars((string) ($building['charge_per_person'] ?? 0)) ?>">
                <p class="text-[11px] text-gray-400 mt-1">تعداد نفرات هر واحد را در صفحه «واحدها» وارد کنید.</p>
            </div>

            <div data-charge-field="custom">
                <div class="hint-card">
                    مبلغ اختصاصی هر واحد را از صفحه «واحدها» تعیین کنید؛ سپس شارژ ماه با جمع همان مبالغ ساخته می‌شود.
                </div>
            </div>

            <label class="flex items-center gap-3 cursor-pointer" style="background:#f8fafc;border:1px solid #e9eef5;border-radius:12px;padding:12px 14px;">
                <input type="checkbox" name="monthly_charge_enabled" value="1" class="rounded" <?= !empty($building['monthly_charge_enabled']) ? 'checked' : '' ?>>
                <span class="text-sm font-medium text-gray-700">شارژ ماهیانه فعال باشد</span>
            </label>

            <button type="submit" class="btn-primary">ذخیره تنظیمات</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('add-cost', 'ثبت هزینه جدید', 'هزینه یا شارژ موردی'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <input type="hidden" name="action" value="create_cost">
            <?php include 'includes/_cost_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ثبت هزینه</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('edit-cost', 'ویرایش هزینه', 'تغییر عنوان، مبلغ و مهلت'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <input type="hidden" name="action" value="update_cost">
            <input type="hidden" name="cost_id" value="">
            <?php include 'includes/_cost_form_fields.php'; ?>
            <button type="submit" class="btn-primary">ذخیره تغییرات</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('penalty-settings', 'جریمه دیرکرد', 'به‌ازای تأخیر از مهلت پرداخت'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <input type="hidden" name="action" value="create_penalty_setting">
            <div>
                <label for="penalty_type" class="form-label">نوع جریمه</label>
                <select id="penalty_type" name="penalty_type" class="form-input">
                    <option value="percentage">درصد از مبلغ</option>
                    <option value="fixed">مبلغ ثابت (تومان)</option>
                </select>
            </div>
            <div>
                <label for="penalty_value" class="form-label">مقدار جریمه *</label>
                <input type="number" id="penalty_value" name="penalty_value" required min="0" step="1000" inputmode="numeric" class="form-input" placeholder="مثلاً 2 یا 50000">
            </div>
            <div>
                <label for="delay_days" class="form-label">آستانه تأخیر (روز) *</label>
                <input type="number" id="delay_days" name="delay_days" required min="1" inputmode="numeric" class="form-input" placeholder="مثلاً 5">
            </div>
            <button type="submit" class="btn-primary">ذخیره تنظیم جریمه</button>
        </form>
    <?php modal_end(); ?>

    <script>
        /* نمایش فیلد مربوط به روش شارژ انتخاب‌شده */
        (function () {
            var radios = document.querySelectorAll('[data-charge-mode]');
            function sync() {
                var selected = document.querySelector('[data-charge-mode]:checked');
                var mode = selected ? selected.value : 'fixed';
                document.querySelectorAll('[data-charge-field]').forEach(function (el) {
                    el.style.display = el.getAttribute('data-charge-field') === mode ? '' : 'none';
                });
            }
            radios.forEach(function (r) { r.addEventListener('change', sync); });
            sync();
        })();
    </script>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
