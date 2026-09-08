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

// نقش کاربر — ثبت مستقیم فقط برای مدیر
$ctx = building_role_context($building_id);
$is_manager = $ctx['is_manager'];

// عملیات‌های مدیر: پرداخت مستقیم / ثبت بدهی / اجرای موتور دوره‌ای
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    $action = $_POST['form_action'];
    if (!$is_manager) {
        $alert_message = 'این عملیات فقط برای مدیر ساختمان مجاز است.';
    } elseif ($action === 'direct_payment') {
        $unit_id = (int) ($_POST['unit_id'] ?? 0);
        $amount = (float) en_digits($_POST['amount'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($unit_id <= 0 || $amount <= 0) {
            $alert_message = 'واحد و مبلغ معتبر را وارد کنید.';
        } else {
            $response = callAPI('POST', '/buildings/' . $building_id . '/direct-payments', [
                'unit_id' => $unit_id, 'amount' => $amount, 'notes' => $notes !== '' ? $notes : null,
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'پرداخت مستقیم ثبت و به حساب واحد منظور شد.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'ثبت پرداخت ناموفق بود.';
            }
        }
    } elseif ($action === 'unit_charge') {
        $unit_id = (int) ($_POST['unit_id'] ?? 0);
        $amount = (float) en_digits($_POST['amount'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($unit_id <= 0 || $amount <= 0 || $title === '') {
            $alert_message = 'واحد، مبلغ و عنوان بدهی را وارد کنید.';
        } else {
            $response = callAPI('POST', '/buildings/' . $building_id . '/unit-charges', [
                'unit_id' => $unit_id, 'amount' => $amount, 'title' => $title,
            ]);
            if (!empty($response['success'])) {
                $alert_message = 'بدهی برای واحد ثبت و صادر شد؛ از اعتبار واحد کم می‌شود.';
                $alert_type = 'success';
            } else {
                $alert_message = $response['message'] ?? 'ثبت بدهی ناموفق بود.';
            }
        }
    } elseif ($action === 'run_engine') {
        $response = callAPI('POST', '/buildings/' . $building_id . '/recurring-generate');
        if (!empty($response['success'])) {
            $d = $response['data'] ?? [];
            $alert_message = 'موتور دوره‌ای اجرا شد: ' . ($d['recurring_issued'] ?? 0) . ' هزینهٔ دوره‌ای و '
                . ($d['monthly_issued'] ?? 0) . ' شارژ ماهیانه صادر شد.';
            $alert_type = 'success';
        } else {
            $alert_message = $response['message'] ?? 'اجرای موتور ناموفق بود.';
        }
    }
}

// داده‌ها: لجر، واحدها، ساختمان
$ledger = ['units' => [], 'totals' => ['debt' => 0, 'credit' => 0, 'balance' => 0]];
$units = [];
$building_name = '';
if ($building_id > 0) {
    $building_response = callAPI('GET', '/buildings/' . $building_id);
    if (!empty($building_response['success'])) {
        $building_name = $building_response['data']['name'] ?? '';
    }
    $ledger_response = callAPI('GET', '/buildings/' . $building_id . '/ledger');
    if (!empty($ledger_response['success'])) {
        $ledger = $ledger_response['data'] ?? $ledger;
    }
    $units_response = callAPI('GET', '/buildings/' . $building_id . '/units');
    if (!empty($units_response['success'])) {
        $units = $units_response['data']['units'] ?? [];
    }
}

// تجمیع مانده‌ها به تفکیک شخص (مستأجر، وگرنه مالک)
$persons = [];
foreach ($units as $u) {
    $uid = (int) ($u['id'] ?? 0);
    $name = trim((string) (!empty($u['tenant_name']) ? $u['tenant_name'] : ($u['owner_name'] ?? '')));
    if ($name === '' || !isset($ledger['units'][$uid])) {
        continue;
    }
    if (!isset($persons[$name])) {
        $persons[$name] = ['balance' => 0.0, 'units' => []];
    }
    $persons[$name]['balance'] += (float) $ledger['units'][$uid]['balance'];
    $persons[$name]['units'][] = (string) ($u['unit_number'] ?? $uid);
}
uksort($persons, static fn($a, $b) => strcmp($a, $b));

$totals = $ledger['totals'] ?? ['debt' => 0, 'credit' => 0, 'balance' => 0];

$page_title = 'حسابداری ساختمان';
$header_sub = $building_name ?: 'بدهکار و طلبکار واحدها';
$back_url = 'dashboard.php?building_id=' . $building_id;
$nav_active = 'none';
$nav_building_id = $building_id;
require_once 'includes/header.php';
?>

<main class="p-5">

    <?php if ($is_manager): ?>
        <div class="flex gap-2 flex-wrap mb-4">
            <?php modal_open_button('direct-payment', '📥 ثبت پرداخت واحد'); ?>
            <?php modal_open_button('unit-charge', '📤 ثبت بدهی برای واحد'); ?>
            <form method="POST" action="" style="display:contents;">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="run_engine">
                <button type="submit" class="btn-secondary" style="font-size:12px;">⚙️ اجرای موتور دوره‌ای</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- خلاصه مالی -->
    <div class="grid grid-cols-3 gap-3 mb-5">
        <div class="card p-3 text-center">
            <p class="text-[10px] text-gray-400 mb-1">مجموع طلب ساختمان از واحدها</p>
            <p class="text-sm font-black text-red-600"><?= fa_number($totals['debt']) ?></p>
        </div>
        <div class="card p-3 text-center">
            <p class="text-[10px] text-gray-400 mb-1">مجموع بستانکاری واحدها</p>
            <p class="text-sm font-black text-emerald-600"><?= fa_number($totals['credit']) ?></p>
        </div>
        <div class="card p-3 text-center">
            <p class="text-[10px] text-gray-400 mb-1">ماندهٔ خالص</p>
            <p class="text-sm font-black <?= ($totals['balance'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' ?>"><?= fa_number($totals['balance']) ?></p>
        </div>
    </div>

    <p class="text-[11px] text-gray-400 mb-4 leading-5">
        💡 هر بدهیِ صادرشده از اعتبار واحد کم می‌شود؛ هر پرداخت تأییدشده به حساب واحد می‌نشیند.
        ماندهٔ منفی یعنی واحد <strong>بدهکار</strong> و مثبت یعنی <strong>طلبکار</strong> است.
    </p>

    <?php if (empty($ledger['units'])): ?>
        <div class="empty-state">
            <div style="font-size: 34px; margin-bottom: 8px;">🧾</div>
            هنوز گردش مالی برای واحدها ثبت نشده است.
        </div>
    <?php else: ?>

        <!-- تب واحدها -->
        <h2 class="section-title">گردش حساب واحدها (<?= fa_digits(count($ledger['units'])) ?> واحد)</h2>
        <div class="space-y-3">
            <?php foreach ($ledger['units'] as $lu): ?>
                <?php
                $bal = (float) $lu['balance'];
                $state_chip = $bal < 0
                    ? ['بدهکار ' . fa_number(-$bal) . ' تومان', 'chip-red']
                    : ($bal > 0 ? ['طلبکار ' . fa_number($bal) . ' تومان', 'chip-green'] : ['تسویه', 'chip-gray']);
                ?>
                <details class="card p-0 overflow-hidden">
                    <summary class="p-4 flex items-center justify-between gap-2 cursor-pointer list-none" style="list-style:none;">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="w-9 h-9 rounded-xl flex items-center justify-center text-sm font-black flex-shrink-0 <?= $bal < 0 ? 'bg-red-50 text-red-600' : ($bal > 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500') ?>">
                                <?= htmlspecialchars(mb_substr((string) $lu['unit_number'], 0, 3, 'UTF-8')) ?>
                            </span>
                            <div class="min-w-0">
                                <p class="font-bold text-gray-800 text-sm">واحد <?= fa_digits($lu['unit_number']) ?></p>
                                <p class="text-[10px] text-gray-400 mt-0.5"><?= fa_digits(count($lu['entries'])) ?> تراکنش — برای گردش حساب باز کنید</p>
                            </div>
                        </div>
                        <span class="chip <?= $state_chip[1] ?>" style="white-space:nowrap;"><?= $state_chip[0] ?></span>
                    </summary>
                    <div class="border-t border-gray-100 overflow-x-auto">
                        <table class="w-full text-[11px]">
                            <thead>
                                <tr class="bg-gray-50 text-gray-500">
                                    <th class="px-3 py-2 text-right font-bold">تاریخ</th>
                                    <th class="px-3 py-2 text-right font-bold">شرح</th>
                                    <th class="px-3 py-2 text-left font-bold">بدهکار</th>
                                    <th class="px-3 py-2 text-left font-bold">بستانکار</th>
                                    <th class="px-3 py-2 text-left font-bold">مانده</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lu['entries'] as $en): ?>
                                    <tr class="border-t border-gray-50">
                                        <td class="px-3 py-2 text-gray-400 whitespace-nowrap">
                                            <?= $en['ts'] ? fa_date($en['ts']) : '—' ?>
                                        </td>
                                        <td class="px-3 py-2 text-gray-700"><?= htmlspecialchars($en['title']) ?></td>
                                        <td class="px-3 py-2 text-left text-red-600 font-bold"><?= $en['debit'] > 0 ? fa_number($en['debit']) : '' ?></td>
                                        <td class="px-3 py-2 text-left text-emerald-600 font-bold"><?= $en['credit'] > 0 ? fa_number($en['credit']) : '' ?></td>
                                        <td class="px-3 py-2 text-left font-black <?= $en['balance'] < 0 ? 'text-red-600' : 'text-emerald-600' ?>"><?= fa_number($en['balance']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>

        <!-- تب اشخاص -->
        <?php if (!empty($persons)): ?>
            <h2 class="section-title">مانده به تفکیک شخص</h2>
            <div class="space-y-2">
                <?php foreach ($persons as $pname => $p): ?>
                    <?php $pb = round((float) $p['balance'], 2); ?>
                    <div class="card p-3 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($pname) ?></p>
                            <p class="text-[10px] text-gray-400 mt-0.5">
                                واحد <?= fa_digits(implode('، ', array_map('fa_digits', $p['units']))) ?>
                            </p>
                        </div>
                        <span class="chip <?= $pb < 0 ? 'chip-red' : ($pb > 0 ? 'chip-green' : 'chip-gray') ?>" style="white-space:nowrap;">
                            <?= $pb < 0 ? 'بدهکار ' . fa_number(-$pb) : ($pb > 0 ? 'طلبکار ' . fa_number($pb) : 'تسویه') ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</main>

<?php if ($is_manager): ?>

    <?php modal_start('direct-payment', 'ثبت پرداخت مستقیم واحد', 'بدون درخواست ساکن؛ بلافاصله تأیید و به حساب واحد منظور می‌شود'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="direct_payment">
            <div>
                <label class="form-label">واحد *</label>
                <select name="unit_id" required class="form-input">
                    <option value="">— انتخاب واحد —</option>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?= (int) ($unit['id'] ?? 0) ?>">
                            واحد <?= fa_digits($unit['unit_number'] ?? '') ?>
                            <?php $occ = trim(($unit['tenant_name'] ?? '') . (!empty($unit['tenant_name']) && !empty($unit['owner_name']) ? ' / ' : '') . ($unit['owner_name'] ?? '')); ?>
                            <?= $occ !== '' ? '— ' . htmlspecialchars($occ) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">مبلغ پرداخت (تومان) *</label>
                <input type="number" name="amount" required min="1000" step="1" inputmode="numeric" class="form-input" placeholder="مثال: 500000">
                <p class="text-[11px] text-gray-400 mt-1">اگر بیشتر از بدهی باشد، واحد طلبکار می‌شود و از بدهی‌های بعدی کم می‌گردد.</p>
            </div>
            <div>
                <label class="form-label">یادداشت (اختیاری)</label>
                <input type="text" name="notes" class="form-input" placeholder="مثال: واریز کارت به کارت">
            </div>
            <button type="submit" class="btn-primary">ثبت و تأیید پرداخت</button>
        </form>
    <?php modal_end(); ?>

    <?php modal_start('unit-charge', 'ثبت بدهی برای واحد', 'بدهی بلافاصله صادر می‌شود و از اعتبار واحد کم می‌گردد'); ?>
        <form method="POST" action="" class="space-y-4" data-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="unit_charge">
            <div>
                <label class="form-label">واحد *</label>
                <select name="unit_id" required class="form-input">
                    <option value="">— انتخاب واحد —</option>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?= (int) ($unit['id'] ?? 0) ?>">
                            واحد <?= fa_digits($unit['unit_number'] ?? '') ?>
                            <?php $occ = trim(($unit['tenant_name'] ?? '') . (!empty($unit['tenant_name']) && !empty($unit['owner_name']) ? ' / ' : '') . ($unit['owner_name'] ?? '')); ?>
                            <?= $occ !== '' ? '— ' . htmlspecialchars($occ) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">عنوان بدهی *</label>
                <input type="text" name="title" required class="form-input" placeholder="مثال: جریمه دیرکرد، هزینه پارکینگ">
            </div>
            <div>
                <label class="form-label">مبلغ (تومان) *</label>
                <input type="number" name="amount" required min="1000" step="1" inputmode="numeric" class="form-input" placeholder="مثال: 200000">
            </div>
            <button type="submit" class="btn-primary">ثبت و صدور بدهی</button>
        </form>
    <?php modal_end(); ?>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
