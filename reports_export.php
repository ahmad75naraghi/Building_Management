<?php
/**
 * خروجی اکسل گزارش‌های مالی — فقط مدیر ساختمان.
 *
 *   reports_export.php?building_id=1&type=ledger    ریز تراکنش‌های هر واحد (لجر کامل)
 *   reports_export.php?building_id=1&type=monthly   ریز مانده‌ها به تفکیک ماه شمسی
 *
 * خروجی در قالب SpreadsheetML (سازگار با اکسل، راست‌به‌چپ) دانلود می‌شود.
 */
require_once 'includes/api_helper.php';

if (!isset($_SESSION['token']) || empty($_SESSION['token'])) {
    header('Location: auth.php');
    exit;
}

$building_id = (int) ($_GET['building_id'] ?? $_SESSION['active_building_id'] ?? 0);
$type = (string) ($_GET['type'] ?? 'ledger');

$ctx = building_role_context($building_id);
if ($building_id <= 0 || empty($ctx['is_manager'])) {
    header('Location: reports.php' . ($building_id > 0 ? '?building_id=' . $building_id : ''));
    exit;
}

// نام ساختمان برای عنوان کاربرگ
$building_name = 'ساختمان';
$building_response = callAPI('GET', '/buildings/' . $building_id);
if (!empty($building_response['success'])) {
    $building_name = (string) ($building_response['data']['name'] ?? $building_name);
}

/** ساخت سلول متنی/عددی */
function xls_cell($value, bool $isNumber = false, string $style = ''): string
{
    $styleAttr = $style !== '' ? ' ss:StyleID="' . $style . '"' : '';
    if ($isNumber) {
        return '<Cell' . $styleAttr . '><Data ss:Type="Number">' . $value . '</Data></Cell>';
    }
    return '<Cell' . $styleAttr . '><Data ss:Type="String">'
        . htmlspecialchars((string) $value, ENT_XML1, 'UTF-8')
        . '</Data></Cell>';
}

function xls_row(array $cells): string
{
    return '<Row>' . implode('', $cells) . '</Row>';
}

$sheets = [];

if ($type === 'monthly') {
    // ---------------- گزارش ماهانه ----------------
    $response = callAPI('GET', '/buildings/' . $building_id . '/monthly-report');
    if (empty($response['success'])) {
        header('Location: reports.php?building_id=' . $building_id);
        exit;
    }
    $report = $response['data'];

    // کاربرگ ۱: جمع ماهانهٔ کل ساختمان
    $rows = [
        xls_row([
            xls_cell('ماه', false, 'hdr'),
            xls_cell('صادرشده (تومان)', false, 'hdr'),
            xls_cell('پرداخت‌شده (تومان)', false, 'hdr'),
            xls_cell('جمع ماه', false, 'hdr'),
            xls_cell('مانده پایان ماه', false, 'hdr'),
        ]),
    ];
    foreach ($report['months'] ?? [] as $m) {
        $rows[] = xls_row([
            xls_cell($m['label']),
            xls_cell($m['charge'], true),
            xls_cell($m['paid'], true),
            xls_cell($m['net'], true),
            xls_cell($m['balance_end'], true),
        ]);
    }
    $t = $report['totals'] ?? [];
    $rows[] = xls_row([
        xls_cell('جمع کل', false, 'hdr'),
        xls_cell($t['charge'] ?? 0, true, 'hdr'),
        xls_cell($t['paid'] ?? 0, true, 'hdr'),
        xls_cell(round(($t['paid'] ?? 0) - ($t['charge'] ?? 0), 2), true, 'hdr'),
        xls_cell($t['balance_now'] ?? 0, true, 'hdr'),
    ]);
    $sheets[] = ['name' => 'خلاصه ماهانه', 'rows' => $rows];

    // کاربرگ ۲: ریز واحدها به تفکیک ماه
    $rows = [
        xls_row([
            xls_cell('واحد', false, 'hdr'),
            xls_cell('ماه', false, 'hdr'),
            xls_cell('صادرشده', false, 'hdr'),
            xls_cell('پرداخت‌شده', false, 'hdr'),
            xls_cell('مانده پایان ماه', false, 'hdr'),
        ]),
    ];
    foreach ($report['units'] ?? [] as $unit) {
        foreach ($unit['months'] ?? [] as $m) {
            $rows[] = xls_row([
                xls_cell('واحد ' . $unit['unit_number']),
                xls_cell($m['label']),
                xls_cell($m['charge'], true),
                xls_cell($m['paid'], true),
                xls_cell($m['balance_end'], true),
            ]);
        }
        if (!empty($unit['months'])) {
            $rows[] = xls_row([
                xls_cell('واحد ' . $unit['unit_number'] . ' — مانده فعلی', false, 'hdr'),
                xls_cell(''),
                xls_cell(''),
                xls_cell(''),
                xls_cell($unit['balance_now'], true, 'hdr'),
            ]);
        }
    }
    $sheets[] = ['name' => 'ریز واحدها', 'rows' => $rows];
} else {
    // ---------------- لجر کامل واحدها ----------------
    $response = callAPI('GET', '/buildings/' . $building_id . '/ledger');
    if (empty($response['success'])) {
        header('Location: reports.php?building_id=' . $building_id);
        exit;
    }
    $ledger = $response['data'];

    $rows = [
        xls_row([
            xls_cell('واحد', false, 'hdr'),
            xls_cell('تاریخ', false, 'hdr'),
            xls_cell('شرح', false, 'hdr'),
            xls_cell('بدهکار', false, 'hdr'),
            xls_cell('بستانکار', false, 'hdr'),
            xls_cell('مانده', false, 'hdr'),
        ]),
    ];
    foreach ($ledger['units'] ?? [] as $unit) {
        foreach ($unit['entries'] ?? [] as $entry) {
            $rows[] = xls_row([
                xls_cell('واحد ' . $unit['unit_number']),
                xls_cell(!empty($entry['ts']) ? fa_date($entry['ts']) : ''),
                xls_cell($entry['title'] ?? ''),
                xls_cell(!empty($entry['debit']) ? $entry['debit'] : '', !empty($entry['debit'])),
                xls_cell(!empty($entry['credit']) ? $entry['credit'] : '', !empty($entry['credit'])),
                xls_cell($entry['balance'], true),
            ]);
        }
        $stateLabel = $unit['balance'] < 0 ? 'بدهکار' : ($unit['balance'] > 0 ? 'طلبکار' : 'تسویه');
        $rows[] = xls_row([
            xls_cell('واحد ' . $unit['unit_number'] . ' — مانده نهایی (' . $stateLabel . ')', false, 'hdr'),
            xls_cell(''),
            xls_cell(''),
            xls_cell($unit['total_share'] ?? 0, true, 'hdr'),
            xls_cell($unit['total_paid'] ?? 0, true, 'hdr'),
            xls_cell($unit['balance'], true, 'hdr'),
        ]);
    }
    $totals = $ledger['totals'] ?? [];
    $rows[] = xls_row([
        xls_cell('جمع ساختمان', false, 'hdr'),
        xls_cell(''),
        xls_cell(''),
        xls_cell($totals['debt'] ?? 0, true, 'hdr'),
        xls_cell($totals['credit'] ?? 0, true, 'hdr'),
        xls_cell($totals['balance'] ?? 0, true, 'hdr'),
    ]);
    $sheets[] = ['name' => 'لجر واحدها', 'rows' => $rows];
}

// ---------------- ساخت فایل SpreadsheetML ----------------
$wb = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$wb .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
$wb .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
$wb .= '<Styles>'
    . '<Style ss:ID="hdr"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#010A21" ss:Pattern="Solid"/></Style>'
    . '</Styles>' . "\n";
foreach ($sheets as $sheet) {
    $name = htmlspecialchars($sheet['name'], ENT_XML1, 'UTF-8');
    $wb .= '<Worksheet ss:Name="' . $name . '"><Table>' . "\n"
        . implode("\n", $sheet['rows']) . "\n"
        . '</Table>'
        . '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><DisplayRightToLeft/></WorksheetOptions>'
        . '</Worksheet>' . "\n";
}
$wb .= '</Workbook>';

[$jy, $jm] = \App\Utilities\JalaliHelper::toJalali((int) date('Y'), (int) date('n'), (int) date('j'));
$filename = ($type === 'monthly' ? 'monthly-report' : 'ledger') . '-' . $building_id . '-' . sprintf('%04d-%02d', $jy, $jm) . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo $wb;
exit;
