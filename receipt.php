<?php
/**
 * رسید چاپی پرداخت — قابل چاپ/ذخیرهٔ PDF از مرورگر.
 * دسترسی توسط خودِ اندپوینت پرداخت کنترل می‌شود (پرداخت‌کننده یا مدیر).
 */
require_once 'includes/api_helper.php';

if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header('Location: auth.php');
    exit;
}

$payment_id = (int) ($_GET['payment_id'] ?? 0);
$payment = null;
$building_name = 'ساختمان';
$unit_number = '';
$payer_name = '';

if ($payment_id > 0) {
    $response = callAPI('GET', '/payments/' . $payment_id);
    if (!empty($response['success'])) {
        $payment = $response['data'] ?? null;
    }
}

if (!$payment) {
    http_response_code(404);
    $payment_error = 'پرداخت پیدا نشد یا به آن دسترسی ندارید.';
} else {
    $building_id = (int) ($payment['building_id'] ?? 0);
    if ($building_id > 0) {
        $b = callAPI('GET', '/buildings/' . $building_id);
        if (!empty($b['success'])) {
            $building_name = (string) ($b['data']['name'] ?? $building_name);
        }
        $m = callAPI('GET', '/buildings/' . $building_id . '/members');
        if (!empty($m['success'])) {
            foreach ($m['data'] ?? [] as $member) {
                if ((int) ($member['user_id'] ?? $member['id'] ?? 0) === (int) ($payment['user_id'] ?? 0)) {
                    $payer_name = (string) ($member['name'] ?? '');
                    break;
                }
            }
        }
        if (!empty($payment['unit_id'])) {
            $u = callAPI('GET', '/buildings/' . $building_id . '/units');
            if (!empty($u['success'])) {
                foreach ($u['data'] ?? [] as $unit) {
                    if ((int) ($unit['id'] ?? 0) === (int) $payment['unit_id']) {
                        $unit_number = (string) ($unit['unit_number'] ?? '');
                        if ($payer_name === '' && !empty($unit['owner_name'])) {
                            $payer_name = (string) $unit['owner_name'];
                        }
                        break;
                    }
                }
            }
        }
    }
}

$status = (string) ($payment['status'] ?? '');
$status_label = function_exists('payment_status_label') ? payment_status_label($status) : $status;
$paid_amount = ($payment['amount_paid'] ?? null) !== null
    ? (float) $payment['amount_paid']
    : (float) ($payment['share_amount'] ?? 0);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>رسید پرداخت | مدیریت ساختمان</title>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Vazirmatn, Tahoma, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            padding: 24px 16px 60px;
        }
        .receipt-actions {
            max-width: 560px;
            margin: 0 auto 14px;
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        .receipt-actions a, .receipt-actions button {
            font: inherit;
            font-size: 0.85rem;
            font-weight: 700;
            padding: 10px 22px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-print { background: #d4a373; color: #fff; }
        .btn-back { background: #e2e8f0; color: #0f172a; }
        .receipt-paper {
            max-width: 560px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 28px 26px;
            box-shadow: 0 8px 30px rgba(2, 6, 23, 0.08);
        }
        .receipt-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px dashed #cbd5e1;
            padding-bottom: 14px;
            margin-bottom: 16px;
        }
        .receipt-head h1 { font-size: 1.05rem; }
        .receipt-head p { font-size: 0.75rem; color: #64748b; margin-top: 4px; }
        .receipt-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .badge-confirmed { background: #dcfce7; color: #166534; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .receipt-amount {
            text-align: center;
            padding: 14px 0 18px;
            border-bottom: 2px dashed #cbd5e1;
            margin-bottom: 14px;
        }
        .receipt-amount .value { font-size: 1.9rem; font-weight: 800; }
        .receipt-amount .unit-label { font-size: 0.75rem; color: #64748b; }
        .receipt-rows { display: grid; gap: 9px; }
        .receipt-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 0.84rem;
        }
        .receipt-row .k { color: #64748b; flex-shrink: 0; }
        .receipt-row .v { font-weight: 700; text-align: left; }
        .receipt-note {
            margin-top: 16px;
            font-size: 0.72rem;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
            line-height: 1.9;
        }
        .receipt-sign {
            display: flex;
            justify-content: space-between;
            margin-top: 34px;
            font-size: 0.78rem;
            color: #334155;
        }
        .receipt-sign span {
            border-top: 1px dotted #94a3b8;
            padding-top: 6px;
            width: 170px;
            text-align: center;
        }
        .receipt-error {
            max-width: 560px;
            margin: 40px auto;
            background: #fff;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: 14px;
            padding: 24px;
            text-align: center;
            line-height: 2;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .receipt-actions { display: none; }
            .receipt-paper {
                box-shadow: none;
                border: 1px solid #94a3b8;
                border-radius: 0;
                max-width: 100%;
            }
        }
    </style>
</head>
<body class="receipt-standalone">

<?php if (!$payment): ?>
    <div class="receipt-error">
        ⚠️ <?= htmlspecialchars($payment_error ?? 'خطا در دریافت اطلاعات.') ?><br>
        <a href="costs.php" style="color:#0f172a;">بازگشت به صفحهٔ هزینه‌ها</a>
    </div>
<?php else: ?>
    <div class="receipt-actions">
        <button type="button" class="btn-print" onclick="window.print()">🖨️ چاپ / ذخیرهٔ PDF</button>
        <a class="btn-back" href="costs.php">بازگشت</a>
    </div>

    <div class="receipt-paper">
        <div class="receipt-head">
            <div>
                <h1>🧾 رسید پرداخت شارژ/هزینه</h1>
                <p><?= htmlspecialchars($building_name, ENT_QUOTES) ?></p>
            </div>
            <span class="receipt-badge <?= $status === 'confirmed' ? 'badge-confirmed' : ($status === 'rejected' ? 'badge-rejected' : 'badge-pending') ?>">
                <?= htmlspecialchars($status_label, ENT_QUOTES) ?>
            </span>
        </div>

        <div class="receipt-amount">
            <div class="value"><?= fa_digits(number_format($paid_amount)) ?></div>
            <div class="unit-label">تومان<?= $paid_amount > 0 && ($payment['amount_paid'] ?? null) === null ? ' (سهم واحد)' : '' ?></div>
        </div>

        <div class="receipt-rows">
            <div class="receipt-row"><span class="k">عنوان هزینه</span><span class="v"><?= htmlspecialchars((string) ($payment['cost_title'] ?? '—'), ENT_QUOTES) ?></span></div>
            <div class="receipt-row"><span class="k">پرداخت‌کننده</span><span class="v"><?= htmlspecialchars($payer_name !== '' ? $payer_name : '—', ENT_QUOTES) ?></span></div>
            <?php if ($unit_number !== ''): ?>
            <div class="receipt-row"><span class="k">واحد</span><span class="v"><?= fa_digits(htmlspecialchars($unit_number, ENT_QUOTES)) ?></span></div>
            <?php endif; ?>
            <div class="receipt-row"><span class="k">شمارهٔ ردیف</span><span class="v"><?= fa_digits((string) $payment_id) ?></span></div>
            <?php if (!empty($payment['created_at'])): ?>
            <div class="receipt-row"><span class="k">تاریخ صدور</span><span class="v"><?= htmlspecialchars(fa_datetime($payment['created_at']), ENT_QUOTES) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($payment['payment_date'])): ?>
            <div class="receipt-row"><span class="k">تاریخ پرداخت</span><span class="v"><?= htmlspecialchars(fa_datetime($payment['payment_date']), ENT_QUOTES) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($payment['confirmed_at'])): ?>
            <div class="receipt-row"><span class="k">تاریخ تأیید مدیر</span><span class="v"><?= htmlspecialchars(fa_datetime($payment['confirmed_at']), ENT_QUOTES) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($payment['notes'])): ?>
            <div class="receipt-row"><span class="k">توضیح</span><span class="v"><?= htmlspecialchars((string) $payment['notes'], ENT_QUOTES) ?></span></div>
            <?php endif; ?>
        </div>

        <div class="receipt-sign">
            <span>امضای پرداخت‌کننده</span>
            <span>امضای مدیر ساختمان</span>
        </div>

        <p class="receipt-note">
            این رسید به‌صورت خودکار توسط سامانهٔ مدیریت ساختمان تولید شده است.
            <?= $status === 'confirmed' ? 'مبلغ این پرداخت تأیید و به حساب واحد منظور شده است.' : 'این پرداخت هنوز تأیید نهایی مدیر ساختمان را ندارد.' ?>
        </p>
    </div>
<?php endif; ?>

</body>
</html>
