<?php
/**
 * ساخت جفت‌کلید VAPID برای «اعلان فوری وب» (Web Push).
 *
 * اجرا روی سرور (یک‌بار):
 *   php scripts/vapid_generate.php
 *
 * خروجی سه مقدار برای `.env` است:
 *   VAPID_PUBLIC_KEY=...
 *   VAPID_PRIVATE_KEY=...
 *   VAPID_SUBJECT=mailto:شما@مثال.com   ← ایمیل خودتان را جایگزین کنید
 *
 * نیازمندی: افزونهٔ openssl با پشتیبانی منحنی P-256 (روی همهٔ هاست‌های رایج هست).
 */

declare(strict_types=1);

if (!extension_loaded('openssl')) {
    fwrite(STDERR, "افزونهٔ openssl در دسترس نیست.\n");
    exit(1);
}

$res = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
if ($res === false) {
    fwrite(STDERR, "ساخت کلید ممکن نشد (پشتیبانی منحنی prime256v1 لازم است).\n");
    exit(1);
}

$details = openssl_pkey_get_details($res);
$publicRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];

openssl_pkey_export($res, $privPem);
// تبدیل PEM به DER برای ذخیرهٔ تک‌خطی در .env
$privDer = base64_decode(str_replace(
    ["\n", '-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----'],
    '',
    $privPem
));

$b64url = static fn(string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

echo "\nاین سه خط را در فایل .env قرار دهید:\n";
echo "--------------------------------------------------\n";
echo 'VAPID_PUBLIC_KEY=' . $b64url($publicRaw) . "\n";
echo 'VAPID_PRIVATE_KEY=' . $b64url($privDer) . "\n";
echo "VAPID_SUBJECT=mailto:admin@example.com\n";
echo "--------------------------------------------------\n";
echo "سپس مهاجرت ۰۳۷ و ۰۳۸ را اجرا کنید: php scripts/migrator.php\n";
