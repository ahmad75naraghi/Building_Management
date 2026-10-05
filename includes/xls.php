<?php
/**
 * کمکی‌های مشترک خروجی اکسل (SpreadsheetML — سازگار با Excel، راست‌به‌چپ).
 * استفاده‌شده در reports_export.php و list_export.php
 */

if (function_exists('xls_cell')) {
    return;
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

/** ساخت یک ردیف از سلول‌ها */
function xls_row(array $cells): string
{
    return '<Row>' . implode('', $cells) . '</Row>';
}

/**
 * ساخت و ارسال فایل اکسل (خروجی را جریان داده و ختم می‌کند).
 *
 * @param array<array{name:string, rows:array<string>}> $sheets کاربرگ‌ها
 */
function xls_send(array $sheets, string $filename): never
{
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

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $wb;
    exit;
}
