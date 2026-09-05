<?php
require_once 'includes/api_helper.php';

// بررسی لاگین
if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header("Location: login.php");
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);

$alert_message = '';
$alert_type = 'error';

// ثبت هزینه جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_cost') {
        $charge_kind = $_POST['charge_kind'] ?? 'normal';
        $title = trim($_POST['title'] ?? '');
        $amount_raw = trim($_POST['amount'] ?? '');
        if ($charge_kind === 'monthly' && $title === '') {
            $title = 'شارژ ماهیانه ' . date('Y-m');
        }
        // اگر شارژ ماهیانه است و مبلغ وارد نشده، از شارژ ثابت ساختمان استفاده شود
        if ($charge_kind === 'monthly' && ($amount_raw === '' || (float) $amount_raw <= 0)) {
            $b_resp = callAPI('GET', '/buildings/' . $building_id);
            $fixed = (float) ($b_resp['data']['monthly_charge'] ?? 0);
            if ($fixed > 0) {
                $amount_raw = (string) $fixed;
            }
        }
        if ($title === '' || $amount_raw === '' || (float) $amount_raw <= 0) {
            $alert_message = $charge_kind === 'monthly'
                ? 'مبلغ شارژ ماهیانه مشخص نیست. مبلغ را وارد کنید یا ابتدا «شارژ ثابت ماهیانه» را در همین صفحه تنظیم کنید.'
                : 'عنوان و مبلغ هزینه را به‌درستی وارد کنید.';
        } else {
            $payload = [
                'building_id' => $building_id,
                'title' => $title,
                'description' => trim($_POST['description'] ?? ''),
                'amount' => (float) $amount_raw,
                'cost_type' => $_POST['cost_type'] ?? 'periodic',
                'division_method' => $_POST['division_method'] ?? 'fixed_share',
                'due_date' => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
                'target_audience' => $_POST['target_audience'] ?? 'all',
            ];
            $response = callAPI('POST', '/costs', $payload);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'هزینه با موفقیت ثبت شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت هزینه. لطفاً دوباره تلاش کنید.';
            }
        }
    } elseif ($_POST['action'] === 'confirm_payment') {
        $payment_id = (int) ($_POST['payment_id'] ?? 0);
        if ($payment_id > 0) {
            $response = callAPI('POST', '/payments/' . $payment_id . '/confirm', ['status' => 'confirmed']);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'پرداخت با موفقیت تأیید شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در تأیید پرداخت.';
            }
        }
    } elseif ($_POST['action'] === 'submit_payment') {
        $cost_id = (int) ($_POST['cost_id'] ?? 0);
        if ($cost_id > 0) {
            $payload = [
                'cost_id' => $cost_id,
                'amount_paid' => !empty($_POST['amount_paid']) ? (float) $_POST['amount_paid'] : null,
                'notes' => trim($_POST['notes'] ?? ''),
            ];
            $response = callAPI('POST', '/payments/submit', $payload);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'پرداخت شما ثبت شد. حالا رسید را آپلود کنید.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت پرداخت.';
            }
        }
    } elseif ($_POST['action'] === 'upload_receipt') {
        $payment_id = (int) ($_POST['payment_id'] ?? 0);
        if ($payment_id > 0 && isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $fields = ['is_public' => isset($_POST['is_public']) ? '1' : '0'];
            $files = ['receipt' => $_FILES['receipt']['tmp_name']];
            $response = callAPIUpload('/payments/' . $payment_id . '/upload-receipt', $fields, $files);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'رسید با موفقیت آپلود شد. در انتظار تأیید مدیر.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در آپلود رسید.';
            }
        } else {
            $alert_message = 'لطفاً فایل رسید را انتخاب کنید.';
        }
    } elseif ($_POST['action'] === 'create_monthly') {
        // ثبت یک‌کلیکه شارژ ماه جاری از روی شارژ ثابت (بدون نیاز به مبلغ)
        $response = callAPI('POST', '/costs/monthly-charge', ['building_id' => $building_id]);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'شارژ ماه جاری ثبت شد و به بدهکاری‌ها اضافه گردید.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ثبت شارژ ماهیانه.';
        }
    } elseif ($_POST['action'] === 'save_monthly_setting') {
        $monthly_amount = max(0, (float) ($_POST['monthly_charge'] ?? 0));
        $monthly_enabled = !empty($_POST['monthly_charge_enabled']);
        $response = callAPI('PUT', '/buildings/' . $building_id, [
            'monthly_charge' => $monthly_amount,
            'monthly_charge_enabled' => $monthly_enabled,
        ]);
        if (isset($response['success']) && $response['success'] === true) {
            $alert_message = 'تنظیم شارژ ثابت ماهیانه ذخیره شد.' . ($monthly_enabled && $monthly_amount > 0 ? ' شارژ این ماه هم به‌صورت خودکار ساخته می‌شود.' : '');
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'خطا در ذخیره تنظیم شارژ ثابت.';
        }
    } elseif ($_POST['action'] === 'create_penalty_setting') {
        $penalty_type = ($_POST['penalty_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
        $penalty_value = trim($_POST['penalty_value'] ?? '');
        $delay_days = (int) ($_POST['delay_days'] ?? 1);
        if ($penalty_value === '' || (float) $penalty_value < 0 || $delay_days < 0) {
            $alert_message = 'مقدار جریمه و روز تأخیر را به‌درستی وارد کنید.';
        } else {
            $payload = [
                'building_id' => $building_id,
                'type' => $penalty_type,
                'amount' => (float) $penalty_value,
                'delay_days' => $delay_days,
                'applies_to' => 'unconfirmed_payments',
                'is_active' => true,
            ];
            $response = callAPI('POST', '/penalty-settings', $payload);
            if (isset($response['success']) && $response['success'] === true) {
                $alert_message = 'تنظیم جریمه دیرکرد با موفقیت ذخیره شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'خطا در ثبت تنظیم جریمه.';
            }
        }
    }
}

// دریافت اطلاعات مالی
$financial = [];
$costs = [];
$payments = [];
$building = [];
$building_name = '';
$current_user_id = 0;
$is_manager = false;

// شناسه کاربر جاری (برای نمایش «پرداخت‌های من» و آپلود رسید)
$me_response = callAPI('GET', '/auth/me');
if (isset($me_response['success']) && $me_response['success'] === true) {
    $current_user_id = (int) ($me_response['data']['id'] ?? 0);
}

if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (isset($building_response['success']) && $building_response['success'] === true) {
        $building = $building_response['data'] ?? [];
        $building_name = $building['name'] ?? '';
    }
    $summary_response = callAPI('GET', '/costs/summary', ['building_id' => $building_id]);
    if (isset($summary_response['success']) && $summary_response['success'] === true) {
        $financial = $summary_response['data'] ?? [];
    }
    $costs_response = callAPI('GET', '/costs', ['building_id' => $building_id]);
    if (isset($costs_response['success']) && $costs_response['success'] === true) {
        $costs = $costs_response['data'] ?? [];
    }
    $payments_response = callAPI('GET', '/payments', ['building_id' => $building_id]);
    if (isset($payments_response['success']) && $payments_response['success'] === true) {
        $payments = $payments_response['data'] ?? [];
    }
    // نقش کاربر جاری (مدیر یا عضو) برای محدود کردن دسترسی‌های مدیریتی
    $members_response = callAPI('GET', '/buildings/' . $building_id . '/members');
    if (isset($members_response['success']) && $members_response['success'] === true) {
        foreach (($members_response['data'] ?? []) as $m) {
            if ((int) ($m['user_id'] ?? 0) === $current_user_id && ($m['role'] ?? '') === 'manager') {
                $is_manager = true;
                break;
            }
        }
    }
}

$page_title = 'مالی و شارژ';
$header_sub = $building_name ?: 'هزینه‌ها و پرداخت‌ها';
$back_url = 'building_view.php?id=' . $building_id;
$active_nav = 'home';
require_once 'includes/page_head.php';
?>

<main class="p-5">

    <!-- خلاصه مالی -->
    <div class="bg-gradient-to-l from-blue-600 to-blue-500 rounded-2xl p-5 text-white shadow-lg shadow-blue-600/20">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold">خلاصه مالی ساختمان</h3>
            <span class="bg-white/20 px-3 py-1 rounded-full text-xs"><?= fa_digits($financial['collection_percentage'] ?? 0) ?>٪ وصولی</span>
        </div>
        <div class="grid grid-cols-3 gap-3 text-center">
            <div class="bg-white/10 rounded-xl p-3">
                <p class="text-blue-100 text-xs">مجموع هزینه‌ها</p>
                <p class="font-bold text-lg mt-1"><?= fa_number($financial['total_costs'] ?? 0) ?></p>
            </div>
            <div class="bg-white/10 rounded-xl p-3">
                <p class="text-blue-100 text-xs">وصول شده</p>
                <p class="font-bold text-lg mt-1"><?= fa_number($financial['total_collected'] ?? 0) ?></p>
            </div>
            <div class="bg-white/10 rounded-xl p-3">
                <p class="text-blue-100 text-xs">مانده</p>
                <p class="font-bold text-lg mt-1"><?= fa_number($financial['total_remaining'] ?? 0) ?></p>
            </div>
        </div>
        <div class="mt-4">
            <div class="h-2.5 bg-white/20 rounded-full overflow-hidden">
                <div class="h-full bg-white rounded-full transition-all" style="width: <?= max(0, min(100, (int) round((float) ($financial['collection_percentage'] ?? 0)))) ?>%"></div>
            </div>
            <div class="flex justify-between text-[11px] text-blue-100 mt-2">
                <span><?= fa_digits($financial['costs_count'] ?? 0) ?> هزینه</span>
                <span><?= fa_digits($financial['confirmed_count'] ?? 0) ?> پرداخت تأیید شده از <?= fa_digits($financial['payments_count'] ?? 0) ?></span>
            </div>
        </div>
    </div>

        <?php if ($is_manager): ?>
    <!-- شارژ ثابت ماهیانه -->
    <div class="card p-5 mt-5 border border-emerald-200">
        <h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H2m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            شارژ ثابت ماهیانه
        </h3>
        <p class="text-xs text-gray-500 mb-4">هر ماه به‌صورت خودکار ثبت و به بدهکاری واحدها اضافه می‌شود.</p>
        <form method="POST" action="" class="flex items-end gap-2">
            <input type="hidden" name="action" value="save_monthly_setting">
            <div class="flex-1">
                <label class="form-label text-[11px]">مبلغ ماهیانه (تومان)</label>
                <input type="number" name="monthly_charge" min="0" step="1000" class="form-input text-sm" value="<?= htmlspecialchars((string) ($building['monthly_charge'] ?? 0)) ?>">
            </div>
            <label class="flex items-center gap-1.5 text-xs text-gray-600 pb-3 whitespace-nowrap">
                <input type="checkbox" name="monthly_charge_enabled" value="1" class="rounded" <?= !empty($building['monthly_charge_enabled']) ? 'checked' : '' ?>>
                فعال
            </label>
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-all active:scale-[0.98] flex-shrink-0">
                ذخیره
            </button>
        </form>
        <form method="POST" action="" class="mt-3">
            <input type="hidden" name="action" value="create_monthly">
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold py-2.5 rounded-xl transition-all active:scale-[0.98]">
                ثبت شارژ ماه جاری (<?= fa_digits(date('Y-m')) ?>)
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- دکمه افزودن -->
    <?php if ($is_manager): ?>
    <a href="#add-cost" class="mt-5 w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-4 rounded-2xl shadow-lg shadow-blue-600/25 transition-all active:scale-[0.98]">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        <span>ثبت هزینه / شارژ جدید</span>
    </a>

    <!-- لیست هزینه‌ها -->
    <?php endif; ?>

    <h2 class="section-title">لیست هزینه‌ها</h2>
    <?php if (empty($costs)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">💰</div>
            هنوز هزینه‌ای ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($costs as $cost): ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800"><?= htmlspecialchars($cost['title'] ?? 'بدون عنوان') ?></h3>
                            <?php if (!empty($cost['description'])): ?>
                                <p class="text-sm text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($cost['description']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="text-left flex-shrink-0">
                            <p class="font-bold text-blue-600"><?= fa_number($cost['amount'] ?? 0) ?> <span class="text-xs font-normal">تومان</span></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 mt-3 flex-wrap">
                        <span class="text-xs bg-blue-50 text-blue-700 px-2.5 py-1 rounded-full">
                            <?= ($cost['cost_type'] ?? '') === 'one_time' ? 'یک‌باره' : 'دوره‌ای' ?>
                        </span>
                        <span class="text-xs bg-gray-100 text-gray-600 px-2.5 py-1 rounded-full">
                            <?= htmlspecialchars(($cost['division_method'] ?? 'fixed_share') === 'area' ? 'بر اساس متراژ' : (($cost['division_method'] ?? '') === 'people_count' ? 'بر اساس نفر' : 'سهم ثابت')) ?>
                        </span>
                        <?php if (!empty($cost['due_date'])): ?>
                            <span class="text-xs bg-amber-50 text-amber-700 px-2.5 py-1 rounded-full">مهلت: <?= htmlspecialchars($cost['due_date']) ?></span>
                        <?php endif; ?>
                    </div>
                    <!-- پرداخت ساکن -->
                    <form method="POST" action="" class="mt-3 flex items-end gap-2">
                        <input type="hidden" name="action" value="submit_payment">
                        <input type="hidden" name="cost_id" value="<?= (int) ($cost['id'] ?? 0) ?>">
                        <div class="flex-1">
                            <label class="form-label text-[11px]">مبلغ (اختیاری)</label>
                            <input type="number" name="amount_paid" min="1" step="1000" class="form-input text-sm" placeholder="<?= fa_number($cost['amount'] ?? 0) ?>">
                        </div>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold px-4 py-2.5 rounded-xl transition-all active:scale-[0.98] flex-shrink-0">
                            پرداخت شارژ
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- لیست پرداخت‌ها -->
    <h2 class="section-title">پرداخت‌های ساکنین</h2>
    <?php if (empty($payments)): ?>
        <div class="card empty-state">
            <div class="text-4xl mb-3">🧾</div>
            هنوز پرداختی ثبت نشده است.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($payments as $payment): ?>
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($payment['user_name'] ?? 'کاربر') ?></h3>
                            <p class="text-xs text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($payment['cost_title'] ?? '') ?></p>
                        </div>
                        <div class="text-left flex-shrink-0">
                            <p class="font-bold text-gray-800"><?= fa_number($payment['amount_paid'] ?? 0) ?> <span class="text-[10px] font-normal text-gray-400">تومان</span></p>
                            <span class="inline-block mt-1 text-[10px] px-2 py-0.5 rounded-full <?= htmlspecialchars(payment_status_color($payment['status'] ?? '')) ?>">
                                <?= htmlspecialchars(payment_status_label($payment['status'] ?? '')) ?>
                            </span>
                        </div>
                    </div>
                    <?php if (!empty($payment['receipt_path'])): ?>
                        <p class="text-[11px] text-gray-400 mt-2 truncate" dir="ltr">رسید: <?= htmlspecialchars($payment['receipt_path']) ?></p>
                    <?php endif; ?>

                    <?php if ((int) ($payment['user_id'] ?? 0) === $current_user_id && ($payment['status'] ?? '') !== 'confirmed'): ?>
                        <!-- آپلود رسید توسط ساکن -->
                        <form method="POST" action="" enctype="multipart/form-data" class="mt-3 card bg-gray-50 p-3">
                            <input type="hidden" name="action" value="upload_receipt">
                            <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                            <div class="flex items-center gap-2">
                                <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf" required class="block w-full text-xs text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700">
                            </div>
                            <label class="flex items-center gap-2 mt-2 text-xs text-gray-600">
                                <input type="checkbox" name="is_public" value="1" class="rounded">
                                نمایش رسید برای همه ساکنین
                            </label>
                            <button type="submit" class="mt-2 w-full bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold py-2 rounded-xl transition-all active:scale-[0.98]">
                                آپلود رسید پرداخت
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($is_manager && ($payment['status'] ?? '') !== 'confirmed'): ?>
                        <form method="POST" action="" class="mt-3">
                            <input type="hidden" name="action" value="confirm_payment">
                            <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white text-sm font-bold py-2.5 rounded-xl transition-all active:scale-[0.98]">
                                تأیید پرداخت (مدیر)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- فرم ثبت هزینه -->
    <?php if ($is_manager): ?>
    <div id="add-cost" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
            </svg>
            ثبت هزینه / شارژ جدید
        </h3>
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="create_cost">
            <div>
                <label for="charge_kind" class="form-label">نوع ثبت</label>
                <select id="charge_kind" name="charge_kind" class="form-input" onchange="document.getElementById('amount').required = this.value !== 'monthly'">
                    <option value="normal">هزینه عادی</option>
                    <option value="monthly">شارژ ماهیانه (مبلغ خالی = شارژ ثابت ساختمان)</option>
                </select>
            </div>
            <div>
                <label for="title" class="form-label">عنوان هزینه *</label>
                <input type="text" id="title""مثال: شارژ ماهیانه شهریور">
            </div>
            <div>
                <label for="amount" class="form-label">مبلغ (تومان) *</label>
                <input type="number" id="amount" name="amount" required min="1" step="1000" class="form-input" placeholder="مثال: 500000">
                <p class="text-[11px] text-gray-400 mt-1">برای شارژ ماهیانه می‌توانید خالی بگذارید تا مبلغ شارژ ثابت لحاظ شود.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="cost_type" class="form-label">نوع</label>
                    <select id="cost_type" name="cost_type" class="form-input">
                        <option value="periodic">دوره‌ای</option>
                        <option value="one_time">یک‌باره</option>
                    </select>
                </div>
                <div>
                    <label for="division_method" class="form-label">روش تقسیم</label>
                    <select id="division_method" name="division_method" class="form-input">
                        <option value="fixed_share">سهم ثابت</option>
                        <option value="area">بر اساس متراژ</option>
                        <option value="people_count">بر اساس نفر</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="target_audience" class="form-label">مخاطب</label>
                <select id="target_audience" name="target_audience" class="form-input">
                    <option value="all">همه ساکنین</option>
                    <option value="owners">مالکین</option>
                    <option value="tenants">مستأجرین</option>
                </select>
            </div>
            <div>
                <label for="due_date" class="form-label">مهلت پرداخت (اختیاری)</label>
                <input type="date" id="due_date" name="due_date" class="form-input">
            </div>
            <div>
                <label for="description" class="form-label">توضیحات (اختیاری)</label>
                <textarea id="description" name="description" rows="2" class="form-input" placeholder="توضیح هزینه"></textarea>
            </div>
            <button type="submit" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                ثبت هزینه
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- تنظیم جریمه دیرکرد -->
    <?php if ($is_manager): ?>
    <div id="add-penalty" class="card p-5 mt-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10" />
                <line x1="12" y1="8" x2="12" y2="12" />
                <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
            تنظیم جریمه دیرکرد
        </h3>
        <p class="text-xs text-gray-500 mb-4">هنگام تأیید پرداخت، به‌ازای هر روز تأخیر از مهلت اعلام‌شده، جریمه محاسبه و ثبت می‌شود.</p>
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="action" value="create_penalty_setting">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="penalty_type" class="form-label">نوع جریمه</label>
                    <select id="penalty_type" name="penalty_type" class="form-input">
                        <option value="percentage">درصد از مبلغ</option>
                        <option value="fixed">مبلغ ثابت (تومان)</option>
                    </select>
                </div>
                <div>
                    <label for="penalty_value" class="form-label">مقدار جریمه *</label>
                    <input type="number" id="penalty_value" name="penalty_value" required min="0" step="1000" class="form-input" placeholder="مثلاً 2 یا 50000">
                </div>
            </div>
            <div>
                <label for="delay_days" class="form-label">آستانه تأخیر (روز) *</label>
                <input type="number" id="delay_days" name="delay_days" required min="1" class="form-input" placeholder="مثلاً 5">
            </div>
            <button type="submit" class="btn-primary bg-red-600 hover:bg-red-700">
                ذخیره تنظیم جریمه
            </button>
        </form>
    </div>
    <?php endif; ?>

</main>

<?php require_once 'includes/page_tail.php'; ?>
