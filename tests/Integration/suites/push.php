<?php
/**
 * آزمون اعلان فوری وب (Web Push):
 *  - ابزارهای رمزنگاری پایه (base64url، HKDF با بردار رسمی RFC 5869، تبدیل DER)
 *  - چرخهٔ کامل رمزنگاری/رمزگشایی پیام مطابق RFC 8291
 *  - توکن VAPID (ES256) و راستی‌آزمایی امضا
 *  - ذخیرهٔ اشتراک‌ها و مسیرهای API
 *
 * کلیدهای آزمون ثابت هستند؛ برای اجرا فقط «پارس کلید، امضا و اشتقاق» لازم است
 * (تولید کلید لازم نیست) تا در همهٔ محیط‌های تست قابل اجرا باشد.
 */

declare(strict_types=1);

use App\Services\PushService;
use App\Utilities\JwtHelper;

TestLog::suite('اعلان فوری وب (Web Push)');

/* جفت‌کلید ثابت «سرور» (VAPID) — فقط برای آزمون */
const PUSH_FIXTURE_SERVER_PEM = "-----BEGIN PRIVATE KEY-----\n"
    . "MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgY3/zJl0j+Bg/bYVx\n"
    . "pxwaSmNRSsfOe0rjPSlH6oIUl62hRANCAAQ4n6jBn3eFXhu1AQOAMtymackgNDAx\n"
    . "RO1WwbxsR2sWTPz8/hnjwPp7NAr0ebLtmjujx2SbpVF4VgXMupmUIm1a\n"
    . "-----END PRIVATE KEY-----\n";
const PUSH_FIXTURE_SERVER_PUB = 'BDifqMGfd4VeG7UBA4Ay3KZpySA0MDFE7VbBvGxHaxZM_Pz-GePA-ns0CvR5su2aO6PHZJulUXhWBcy6mZQibVo';

/* جفت‌کلید ثابت «مرورگر» (کلاینت پوش) — فقط برای آزمون */
const PUSH_FIXTURE_CLIENT_PEM = "-----BEGIN PRIVATE KEY-----\n"
    . "MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQghvu2Cm8UiIQmQY6m\n"
    . "1hj4znRbfO2JhBLuJNMbOBMbN8ihRANCAAS/W8SV2wY+khfSTzaYQUkcfHn/CA1B\n"
    . "/OjR4N9b5Y3VLg8gbXEVBma0BIQ2kdUfmCzOATgj/uGVqchMoTbHH9yy\n"
    . "-----END PRIVATE KEY-----\n";
const PUSH_FIXTURE_CLIENT_PUB = 'BL9bxJXbBj6SF9JPNphBSRx8ef8IDUH86NHg31vljdUuDyBtcRUGZrQEhDaR1R-YLM4BOCP-4ZWpyEyhNscf3LI';

/** فعال‌کردن وب‌پوش با کلیدهای ثابت آزمون */
function push_env_enable(): void
{
    $der = base64_decode(str_replace(
        ["\n", '-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----'],
        '',
        PUSH_FIXTURE_SERVER_PEM
    ));
    putenv('VAPID_PUBLIC_KEY=' . PUSH_FIXTURE_SERVER_PUB);
    putenv('VAPID_PRIVATE_KEY=' . PushService::base64urlEncode($der));
    putenv('VAPID_SUBJECT=mailto:test@example.com');
}

/** غیرفعال‌کردن وب‌پوش (پاک‌سازی محیط برای سوئیت‌های بعدی) */
function push_env_disable(): void
{
    putenv('VAPID_PUBLIC_KEY');
    putenv('VAPID_PRIVATE_KEY');
    putenv('VAPID_SUBJECT');
}

/* ------------------------------------------------------------ */

TestLog::run('base64url: کدگذاری/کدگشایی رفت‌وبرگشتی', function () {
    foreach (["\x01", "\x01\x02", "\x01\x02\x03", random_bytes(32), "\xfb\xff\xfe"] as $bin) {
        $enc = PushService::base64urlEncode($bin);
        TestLog::assertTrue('بدون کاراکتر نامعتبر', strpbrk($enc, '+/=' . "\n") === false);
        TestLog::assertSame('رفت‌وبرگشت', $bin, PushService::base64urlDecode($enc));
    }
});

TestLog::run('HKDF: بردار رسمی RFC 5869 (Test Case 1)', function () {
    $ikm = str_repeat("\x0b", 22);
    $salt = hex2bin('000102030405060708090a0b0c');
    $info = hex2bin('f0f1f2f3f4f5f6f7f8f9');
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    TestLog::assertSame('PRK', '077709362c2e32df0ddc3f0dc47bba6390b6c73bb50f9c3122ec844ad7c2b3e5', bin2hex($prk));
    $okm = PushService::hkdf($salt, $ikm, $info, 42);
    TestLog::assertSame(
        'OKM',
        '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865',
        bin2hex($okm)
    );
});

TestLog::run('تبدیل امضای DER به خام (۶۴ بایت)', function () {
    // DER ساختگی: r با بایت صفر پیشرو، s کامل
    $r = str_repeat("\x01", 32);
    $s = "\x80" . str_repeat("\x02", 31); // عدد با بیت بالا → باید بدون پدینگ منتقل شود
    $der = "\x30\x45\x02\x21\x00" . $r . "\x02\x20" . $s;
    $raw = PushService::derSignatureToRaw($der);
    TestLog::assertSame('طول خروجی', 64, strlen($raw));
    TestLog::assertSame('مؤلفهٔ r', $r, substr($raw, 0, 32));
    TestLog::assertSame('مؤلفهٔ s', $s, substr($raw, 32));
});

TestLog::run('وضعیت فعال/غیرفعال سرویس', function () {
    push_env_disable();
    TestLog::assertSame('بدون کلید غیرفعال است', false, PushService::enabled());

    putenv('VAPID_PUBLIC_KEY=کوتاه');
    push_env_enable();
    putenv('VAPID_PUBLIC_KEY=کوتاه'); // کلید نامعتبر بعد از فعال‌سازی
    TestLog::assertSame('کلید عمومی نامعتبر → غیرفعال', false, PushService::enabled());

    push_env_enable();
    TestLog::assertSame('با کلیدهای معتبر فعال است', true, PushService::enabled());
    TestLog::assertSame('بازخوانی کلید عمومی', PUSH_FIXTURE_SERVER_PUB, PushService::publicKey());
});

TestLog::run('توکن VAPID: ساختار و راستی‌آزمایی امضا (ES256)', function () {
    push_env_enable();
    $jwt = PushService::vapidJwt('https://push.example.com');
    TestLog::assertTrue('توکن ساخته شد', is_string($jwt) && $jwt !== '');
    $parts = explode('.', (string) $jwt);
    TestLog::assertSame('سه بخش', 3, count($parts));

    $header = json_decode(PushService::base64urlDecode($parts[0]), true);
    TestLog::assertSame('الگوریتم', 'ES256', $header['alg'] ?? null);
    $payload = json_decode(PushService::base64urlDecode($parts[1]), true);
    TestLog::assertSame('مخاطب', 'https://push.example.com', $payload['aud'] ?? null);
    TestLog::assertSame('موضوع', 'mailto:test@example.com', $payload['sub'] ?? null);
    TestLog::assertTrue('انقضا در آینده', ($payload['exp'] ?? 0) > time());

    $sig = PushService::base64urlDecode($parts[2]);
    TestLog::assertSame('طول امضای خام', 64, strlen($sig));

    // بازسازی DER و راستی‌آزمایی با کلید عمومی سرور
    $rawToDerInt = static function (string $i): string {
        $i = ltrim($i, "\x00");
        if (ord($i[0]) > 0x7f) {
            $i = "\x00" . $i;
        }
        return "\x02" . chr(strlen($i)) . $i;
    };
    $inner = $rawToDerInt(substr($sig, 0, 32)) . $rawToDerInt(substr($sig, 32));
    $der = "\x30" . chr(strlen($inner)) . $inner;
    $pubPem = PushService::publicKeyPemFromRaw(PushService::base64urlDecode(PUSH_FIXTURE_SERVER_PUB));
    $verified = openssl_verify($parts[0] . '.' . $parts[1], $der, $pubPem, OPENSSL_ALGO_SHA256);
    TestLog::assertSame('امضا با کلید عمومی تأیید می‌شود', 1, $verified);
});

TestLog::run('رمزنگاری/رمزگشایی پیام مطابق RFC 8291 (چرخهٔ کامل)', function () {
    push_env_enable();
    $authSecret = random_bytes(16);
    $salt = str_repeat("\x11", 16);
    $plaintext = json_encode([
        'title' => 'هشدار تست',
        'body' => 'پیام آزمایشی اعلان فوری 🎉',
        'url' => 'dashboard.php',
    ], JSON_UNESCAPED_UNICODE);

    $asPubOut = null;
    $body = PushService::encryptPayload(
        PUSH_FIXTURE_CLIENT_PUB,
        PushService::base64urlEncode($authSecret),
        $plaintext,
        $salt,
        PUSH_FIXTURE_SERVER_PEM,
        $asPubOut
    );
    TestLog::assertTrue('بدنهٔ رمزنگاری‌شده ساخته شد', is_string($body) && strlen($body) > 86);
    TestLog::assertSame('طول کلید عمومی سرور در هدر', 65, strlen((string) $asPubOut));

    // --- سمت کلاینت: سربرگ رکورد باز می‌شود ---
    TestLog::assertSame('نمک سربرگ', $salt, substr($body, 0, 16));
    $rs = unpack('N', substr($body, 16, 4))[1];
    TestLog::assertSame('اندازهٔ رکورد', 4096, $rs);
    TestLog::assertSame('طول keyid', 65, ord($body[20]));
    $asPub = substr($body, 21, 65);
    TestLog::assertSame('کلید عمومی سرور در سربرگ', $asPubOut, $asPub);

    // --- سمت کلاینت: استخراج کلید و رمزگشایی ---
    $ecdh = openssl_pkey_derive(PushService::publicKeyPemFromRaw($asPub), PUSH_FIXTURE_CLIENT_PEM, 256);
    TestLog::assertSame('طول اشتراک ECDH', 32, strlen((string) $ecdh));

    $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
    $clientPubRaw = PushService::base64urlDecode(PUSH_FIXTURE_CLIENT_PUB);
    $ikm = PushService::hkdfExpand($prkKey, "WebPush: info\x00" . $clientPubRaw . $asPub, 32);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $key = PushService::hkdfExpand($prk, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = PushService::hkdfExpand($prk, "Content-Encoding: nonce\x00", 12);

    $cipher = substr($body, 86);
    $tag = substr($cipher, -16);
    $decrypted = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    TestLog::assertSame('رمزگشایی موفق در سمت کلاینت', $plaintext . "\x02", $decrypted);

    // دست‌کاری یک بایت باید رمزگشایی را شکست دهد (صحت تگ GCM)
    $tampered = $body;
    $tampered[90] = chr(ord($body[90]) ^ 0xff);
    $cipherT = substr($tampered, 86);
    $bad = openssl_decrypt(substr($cipherT, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, substr($cipherT, -16));
    TestLog::assertSame('پیام دست‌کاری‌شده رد می‌شود', false, $bad);
});

TestLog::run('اندپوینت نامعتبر ارسال نمی‌شود', function () {
    TestLog::assertSame('بدون فیلتر رد می‌شود', 'failed', PushService::sendToEndpoint('http://insecure.example.com', 'x', 'y', '{}'));
    TestLog::assertSame('آدرس نامعتبر رد می‌شود', 'failed', PushService::sendToEndpoint('not-a-url', 'x', 'y', '{}'));
});

/* ---------------- ذخیرهٔ اشتراک‌ها ---------------- */

TestLog::run('ذخیره و بازیابی اشتراک‌های کاربر', function () {
    $uid = make_user('09120000901', 'کاربر پوش');
    TestLog::assertSame('بدون اشتراک', 0, PushService::subscriptionCount($uid));

    PushService::saveSubscription($uid, 'https://push.example.com/ep-1', 'p256dh-1', 'auth-1', 'UA-Test');
    TestLog::assertSame('یک اشتراک', 1, PushService::subscriptionCount($uid));

    // اندپوینت تکراری جایگزین می‌شود، نه اینکه تکراری بسازد
    PushService::saveSubscription($uid, 'https://push.example.com/ep-1', 'p256dh-new', 'auth-new');
    TestLog::assertSame('اندپوینت تکراری جایگزین شد', 1, PushService::subscriptionCount($uid));

    PushService::saveSubscription($uid, 'https://push.example.com/ep-2', 'p256dh-2', 'auth-2');
    TestLog::assertSame('دو دستگاه', 2, PushService::subscriptionCount($uid));

    PushService::removeSubscription($uid, 'https://push.example.com/ep-1');
    TestLog::assertSame('حذف یک اشتراک', 1, PushService::subscriptionCount($uid));
});

/* ---------------- مسیرهای API ---------------- */

if (!defined('API_INTERNAL_DISPATCH')) {
    define('API_INTERNAL_DISPATCH', true);
}
putenv('JWT_SECRET=test-secret-key-for-e2e-render-0123456789');
if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        return [];
    }
}
require_once dirname(__DIR__, 3) . '/includes/api_helper.php';

TestLog::run('مسیرهای وب‌پوش با وب‌پوش غیرفعال', function () {
    push_env_disable();
    $uid = make_user('09120000902', 'کاربر غیرفعال');
    $_SESSION['token'] = JwtHelper::generate(['sub' => $uid, 'role' => 'resident']);

    $r = callAPI_dispatch('GET', '/push/public-key');
    TestLog::assertSame('کلید عمومی: غیرفعال', false, $r['enabled'] ?? null);

    $r = callAPI_dispatch('POST', '/push/subscribe', [
        'endpoint' => 'https://push.example.com/ep',
        'keys' => ['p256dh' => 'p', 'auth' => 'a'],
    ]);
    TestLog::assertSame('ثبت اشتراک در سرور غیرفعال رد می‌شود', 422, $r['http_code'] ?? null);
});

TestLog::run('مسیرهای وب‌پوش با وب‌پوش فعال', function () {
    push_env_enable();
    $uid = make_user('09120000903', 'کاربر فعال پوش');
    $_SESSION['token'] = JwtHelper::generate(['sub' => $uid, 'role' => 'resident']);

    $r = callAPI_dispatch('GET', '/push/public-key');
    TestLog::assertSame('کلید عمومی فعال است', true, $r['enabled'] ?? null);
    TestLog::assertSame('مقدار کلید عمومی', PUSH_FIXTURE_SERVER_PUB, $r['public_key'] ?? null);

    $r = callAPI_dispatch('GET', '/push/status');
    TestLog::assertSame('وضعیت: بدون دستگاه', 0, $r['subscribed_devices'] ?? null);

    // ثبت اشتراک معتبر
    $r = callAPI_dispatch('POST', '/push/subscribe', [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => PUSH_FIXTURE_CLIENT_PUB, 'auth' => PushService::base64urlEncode(random_bytes(16))],
    ]);
    TestLog::assertSame('ثبت اشتراک موفق', true, $r['success'] ?? null);
    TestLog::assertSame('وضعیت: یک دستگاه', 1, callAPI_dispatch('GET', '/push/status')['subscribed_devices'] ?? null);

    // اندپوینت نامعتبر رد می‌شود
    $r = callAPI_dispatch('POST', '/push/subscribe', [
        'endpoint' => 'http://insecure.example.com/ep',
        'keys' => ['p256dh' => 'x', 'auth' => 'y'],
    ]);
    TestLog::assertSame('اندپوینت غیر https رد می‌شود', 422, $r['http_code'] ?? null);

    $r = callAPI_dispatch('POST', '/push/subscribe', [
        'endpoint' => '',
        'keys' => ['p256dh' => 'x', 'auth' => 'y'],
    ]);
    TestLog::assertSame('اندپوینت خالی رد می‌شود', 422, $r['http_code'] ?? null);

    // حذف اشتراک
    $r = callAPI_dispatch('POST', '/push/unsubscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123']);
    TestLog::assertSame('حذف اشتراک موفق', true, $r['success'] ?? null);
    TestLog::assertSame('وضعیت: دوباره صفر', 0, callAPI_dispatch('GET', '/push/status')['subscribed_devices'] ?? null);

    push_env_disable();
});
