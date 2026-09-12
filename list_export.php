<?php
/**
 * خروجی اکسل فهرست‌ها — فقط مدیر ساختمان.
 *
 *   list_export.php?building_id=1&type=members    فهرست اعضا با نقش و واحدها
 *   list_export.php?building_id=1&type=payments   فهرست پرداخت‌ها (همهٔ هزینه‌ها)
 *   list_export.php?building_id=1&type=tickets    فهرست تیکت‌ها
 *
 * خروجی در قالب SpreadsheetML (سازگار با اکسل، راست‌به‌چپ) دانلود می‌شود.
 */
require_once 'includes/api_helper.php';
require_once 'includes/xls.php';

if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header('Location: auth.php');
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);
$type = (string) ($_GET['type'] ?? 'members');
$allowed_types = ['members', 'payments', 'tickets'];
if (!in_array($type, $allowed_types, true)) {
    $type = 'members';
}

$ctx = building_role_context($building_id);
$back_page = $type === 'payments' ? 'costs.php' : ($type === 'tickets' ? 'tickets.php' : 'members.php');
if ($building_id <= 0 || empty($ctx['is_manager'])) {
    header('Location: ' . $back_page . ($building_id > 0 ? '?building_id=' . $building_id : ''));
    exit;
}

// برچسب‌های فارسی دستهٔ تیکت (هم‌راستا با صفحهٔ فهرست) — وضعیت/اولویت از کمکی‌های مشترک
$ticket_category_labels = [
    'technical' => 'فنی',
    'financial' => 'مالی',
    'management' => 'مدیریتی',
    'complaint' => 'شکایت',
    'suggestion' => 'پیشنهاد',
];

$sheets = [];

if ($type === 'members') {
    // ---------------- فهرست اعضا ----------------
    $members_response = callAPI('GET', '/buildings/' . $building_id . '/members');
    if (empty($members_response['success'])) {
        header('Location: members.php?building_id=' . $building_id);
        exit;
    }
    $members = $members_response['data'] ?? [];

    $rows = [
        xls_row([
            xls_cell('نام', false, 'hdr'),
            xls_cell('شماره تماس', false, 'hdr'),
            xls_cell('نقش', false, 'hdr'),
            xls_cell('واحدها', false, 'hdr'),
        ]),
    ];
    foreach ($members as $member) {
        $units = $member['units'] ?? [];
        $unit_labels = [];
        foreach ($units as $u) {
            $rel = $u['relation'] ?? '';
            $rel_label = match ($rel) {
                'owner' => 'مالک',
                'owner_resident' => 'مالک ساکن',
                'tenant' => 'مستأجر',
                default => '',
            };
            $unit_labels[] = ($u['unit_number'] ?? '؟') . ($rel_label !== '' ? ' (' . $rel_label . ')' : '');
        }
        $rows[] = xls_row([
            xls_cell($member['name'] ?? ''),
            xls_cell($member['phone'] ?? ''),
            xls_cell(member_role_label($member['role'] ?? '')),
            xls_cell(implode('، ', $unit_labels)),
        ]);
    }
    $sheets[] = ['name' => 'اعضا', 'rows' => $rows];
} elseif ($type === 'payments') {
    // ---------------- فهرست پرداخت‌ها ----------------
    $payments_response = callAPI('GET', '/payments', ['building_id' => $building_id]);
    if (empty($payments_response['success'])) {
        header('Location: costs.php?building_id=' . $building_id);
        exit;
    }
    $payments = $payments_response['data'] ?? [];

    $rows = [
        xls_row([
            xls_cell('هزینه', false, 'hdr'),
            xls_cell('پرداخت‌کننده', false, 'hdr'),
            xls_cell('واحد', false, 'hdr'),
            xls_cell('سهم (تومان)', false, 'hdr'),
            xls_cell('پرداخت‌شده (تومان)', false, 'hdr'),
            xls_cell('وضعیت', false, 'hdr'),
            xls_cell('تاریخ ثبت', false, 'hdr'),
            xls_cell('تاریخ پرداخت', false, 'hdr'),
        ]),
    ];
    foreach ($payments as $payment) {
        $status = (string) ($payment['status'] ?? '');
        $rows[] = xls_row([
            xls_cell($payment['cost_title'] ?? ''),
            xls_cell($payment['user_name'] ?? ''),
            xls_cell($payment['unit_number'] ?? ''),
            xls_cell((float) ($payment['share_amount'] ?? 0), true),
            xls_cell((float) ($payment['amount_paid'] ?? 0), true),
            xls_cell(payment_status_label($status)),
            xls_cell(!empty($payment['created_at']) ? fa_date($payment['created_at']) : ''),
            xls_cell(!empty($payment['payment_date']) ? fa_date($payment['payment_date']) : ''),
        ]);
    }
    $sheets[] = ['name' => 'پرداخت‌ها', 'rows' => $rows];
} else {
    // ---------------- فهرست تیکت‌ها ----------------
    $tickets_response = callAPI('GET', '/tickets', ['building_id' => $building_id]);
    if (empty($tickets_response['success'])) {
        header('Location: tickets.php?building_id=' . $building_id);
        exit;
    }
    $tickets = $tickets_response['data'] ?? [];

    $rows = [
        xls_row([
            xls_cell('عنوان', false, 'hdr'),
            xls_cell('وضعیت', false, 'hdr'),
            xls_cell('اولویت', false, 'hdr'),
            xls_cell('دسته', false, 'hdr'),
            xls_cell('تاریخ ثبت', false, 'hdr'),
        ]),
    ];
    foreach ($tickets as $ticket) {
        $status = (string) ($ticket['status'] ?? '');
        $priority = (string) ($ticket['priority'] ?? '');
        $category = (string) ($ticket['category'] ?? '');
        $rows[] = xls_row([
            xls_cell($ticket['title'] ?? ''),
            xls_cell(ticket_status_label($status)),
            xls_cell(ticket_priority_label($priority)),
            xls_cell($ticket_category_labels[$category] ?? ($category !== '' ? $category : '—')),
            xls_cell(!empty($ticket['created_at']) ? fa_date($ticket['created_at']) : ''),
        ]);
    }
    $sheets[] = ['name' => 'تیکت‌ها', 'rows' => $rows];
}

[$jy, $jm] = \App\Utilities\JalaliHelper::toJalali((int) date('Y'), (int) date('n'), (int) date('j'));
$filename = $type . '-' . $building_id . '-' . sprintf('%04d-%02d', $jy, $jm) . '.xls';

xls_send($sheets, $filename);
