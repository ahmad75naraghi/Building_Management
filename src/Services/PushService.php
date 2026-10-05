<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Core\Database;
use App\Core\Logger;

/**
 * اعلان فوری وب (Web Push) بر اساس استانداردهای:
 *  - RFC 8291 (رمزنگاری پیام با aes128gcm + ECDH روی منحنی P-256)
 *  - RFC 8292 (VAPID: احراز سرویس با توکن JWT امضاشدهٔ ES256)
 *
 * پیاده‌سازی بدون کتابخانهٔ خارجی است تا روی هاست اشتراکی (با openssl و curl)
 * کار کند. در نبود کلیدهای VAPID یا افزونه‌های لازم، سرویس به‌صورت بی‌صدا
 * غیرفعال می‌شود و هیچ خطایی به جریان اصلی برنامه وارد نمی‌کند.
 *
 * فعال‌سازی: در `.env` —
 *   VAPID_PUBLIC_KEY=...   (خروجی اسکریپت scripts/vapid_generate.php)
 *   VAPID_PRIVATE_KEY=...
 *   VAPID_SUBJECT=mailto:you@example.com
 */
final class PushService
{
    /** آیا وب‌پوش در این نصب فعال است؟ (کلیدها + افزونه‌ها موجود) */
    public static function enabled(): bool
    {
        if (!extension_loaded('openssl') || !function_exists('curl_init')) {
            return false;
        }
        return self::publicKey() !== null && self::privateKeyPem() !== null;
    }

    /** کلید عمومی VAPID (base64url، نقطهٔ فشرده‌نشدهٔ ۶۵ بایتی) */
    public static function publicKey(): ?string
    {
        $key = AppConfig::env('VAPID_PUBLIC_KEY');
        return is_string($key) && strlen(self::base64urlDecode($key)) === 65 ? $key : null;
    }

    /** آدرس تماس مسئول (sub توکن VAPID) */
    public static function subject(): string
    {
        return (string) AppConfig::env('VAPID_SUBJECT', 'mailto:admin@localhost');
    }

    private static function privateKeyPem(): ?string
    {
        $der = self::base64urlDecode((string) AppConfig::env('VAPID_PRIVATE_KEY', ''));
        if ($der === '') {
            return null;
        }
        $pem = "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PRIVATE KEY-----\n";
        $res = @openssl_pkey_get_private($pem);
        if ($res === false) {
            return null;
        }
        return $pem;
    }

    /* ============================================================
     * ابزارهای رمزنگاری پایه
     * ============================================================ */

    public static function base64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $s): string
    {
        $pad = strlen($s) % 4 === 0 ? '' : str_repeat('=', 4 - (strlen($s) % 4));
        $out = base64_decode(strtr($s, '-_', '+/') . $pad, true);
        return $out === false ? '' : $out;
    }

    /** HKDF-Expand (SHA-256) مطابق RFC 5869 */
    public static function hkdfExpand(string $prk, string $info, int $length): string
    {
        $okm = '';
        $t = '';
        $i = 1;
        while (strlen($okm) < $length) {
            $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $okm .= $t;
            $i++;
        }
        return substr($okm, 0, $length);
    }

    /** HKDF کامل: استخراج + بسط */
    public static function hkdf(string $salt, string $ikm, string $info, int $length): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        return self::hkdfExpand($prk, $info, $length);
    }

    /** ساخت PEM کلید عمومی SPKI از روی نقطهٔ خام ۶۵ بایتی (P-256) */
    public static function publicKeyPemFromRaw(string $rawPoint): string
    {
        $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($prefix . $rawPoint), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        return $pem;
    }

    /** تبدیل امضای DER (ECDSA) به فرمت خام ۶۴ بایتی (r||s) برای JWT */
    public static function derSignatureToRaw(string $der): string
    {
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += (ord($der[1]) & 0x7f);
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (!isset($der[$offset]) || ord($der[$offset]) !== 0x02) {
                throw new \RuntimeException('امضای DER نامعتبر است');
            }
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $offset += 2 + $len;
            $int = ltrim($int, "\x00");
            $parts[] = str_pad(substr($int, -32), 32, "\x00", STR_PAD_LEFT);
        }
        return $parts[0] . $parts[1];
    }

    /* ============================================================
     * توکن VAPID (RFC 8292)
     * ============================================================ */

    /** ساخت توکن JWT با امضای ES256 برای مخاطب مشخص (اورجین سرویس پوش) */
    public static function vapidJwt(string $audience): ?string
    {
        $pem = self::privateKeyPem();
        if ($pem === null) {
            return null;
        }
        $header = self::base64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
        $payload = self::base64urlEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => self::subject(),
        ], JSON_UNESCAPED_SLASHES));
        $signed = $header . '.' . $payload;
        $derSig = '';
        if (!openssl_sign($signed, $derSig, $pem, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        return $signed . '.' . self::base64urlEncode(self::derSignatureToRaw($derSig));
    }

    /* ============================================================
     * رمزنگاری پیام (RFC 8291 — aes128gcm)
     * ============================================================ */

    /**
     * رمزنگاری متن پیام برای یک اشتراک پوش.
     * خروجی: بدنهٔ دودویی آمادهٔ ارسال؛ کلید عمومی سرور از طریق $serverPubRawRaw بیرون می‌آید.
     *
     * برای تست، نمک و کلید خصوصی سرور قابل تزریق هستند.
     */
    public static function encryptPayload(
        string $p256dhB64url,
        string $authB64url,
        string $plaintext,
        ?string $saltOverride = null,
        ?string $serverPrivPemOverride = null,
        ?string &$serverPubRawOut = null
    ): ?string {
        $clientPub = self::base64urlDecode($p256dhB64url);
        $authSecret = self::base64urlDecode($authB64url);
        if (strlen($clientPub) !== 65 || strlen($authSecret) !== 16) {
            return null;
        }

        // جفت‌کلید موقت سرور برای هر پیام
        if ($serverPrivPemOverride !== null) {
            $serverPrivPem = $serverPrivPemOverride;
        } else {
            $res = @openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1',
            ]);
            if ($res === false) {
                return null;
            }
            openssl_pkey_export($res, $serverPrivPem);
        }
        $details = openssl_pkey_get_details(openssl_pkey_get_private($serverPrivPem));
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            return null;
        }
        $serverPubRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];

        // اشتراک ECDH با کلید عمومی کلاینت
        $ecdh = @openssl_pkey_derive(self::publicKeyPemFromRaw($clientPub), $serverPrivPem, 256);
        if ($ecdh === false || $ecdh === '') {
            return null;
        }

        $salt = $saltOverride ?? random_bytes(16);

        // RFC 8291 — استخراج کلیدها
        $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
        $ikm = self::hkdfExpand($prkKey, "WebPush: info\x00" . $clientPub . $serverPubRaw, 32);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $key = self::hkdfExpand($prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdfExpand($prk, "Content-Encoding: nonce\x00", 12);

        $tag = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            return null;
        }

        $serverPubRawOut = $serverPubRaw;
        // هدر رکورد aes128gcm: salt(16) + rs(4) + idlen(1) + keyid(65)
        return $salt . pack('N', 4096) . chr(65) . $serverPubRaw . $cipher . $tag;
    }

    /* ============================================================
     * ذخیره‌سازی اشتراک‌ها
     * ============================================================ */

    /** ثبت/به‌روزرسانی اشتراک کاربر (بر اساس اندپوینت تکراری جایگزین می‌شود) */
    public static function saveSubscription(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent = null): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
        $stmt->execute([$userId, $endpoint]);
        $stmt = $db->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        return $stmt->execute([
            $userId, $endpoint, $p256dh, $auth,
            $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            date('Y-m-d H:i:s'),
        ]);
    }

    /** حذف اشتراک کاربر با اندپوینت مشخص */
    public static function removeSubscription(int $userId, string $endpoint): bool
    {
        $stmt = Database::getConnection()->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
        return $stmt->execute([$userId, $endpoint]);
    }

    /** تعداد اشتراک‌های فعال یک کاربر (برای نمایش وضعیت در پروفایل) */
    public static function subscriptionCount(int $userId): int
    {
        $stmt = Database::getConnection()->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /* ============================================================
     * ارسال
     * ============================================================ */

    /**
     * ارسال اعلان به همهٔ دستگاه‌های ثبت‌شدهٔ یک کاربر.
     * اشتراک‌های مرده (404/410) به‌صورت خودکار حذف می‌شوند.
     *
     * @return int تعداد ارسال‌های موفق
     */
    public static function notifyUser(int $userId, string $title, string $body, ?string $url = null): int
    {
        if (!self::enabled()) {
            return 0;
        }
        try {
            $stmt = Database::getConnection()->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
            $stmt->execute([$userId]);
            $subs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::warning('push', 'خطا در خواندن اشتراک‌ها: ' . $e->getMessage());
            return 0;
        }
        if (empty($subs)) {
            return 0;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url ?? 'dashboard.php',
        ], JSON_UNESCAPED_UNICODE);

        $delivered = 0;
        foreach ($subs as $sub) {
            $result = self::sendToEndpoint((string) $sub['endpoint'], (string) $sub['p256dh'], (string) $sub['auth'], $payload);
            if ($result === 'expired') {
                self::removeSubscription($userId, (string) $sub['endpoint']);
            } elseif ($result === 'ok') {
                $delivered++;
            }
        }
        return $delivered;
    }

    /**
     * ارسال یک پیام رمزنگاری‌شده به اندپوینت پوش.
     *
     * @return string 'ok' | 'expired' | 'failed'
     */
    public static function sendToEndpoint(string $endpoint, string $p256dh, string $auth, string $payloadJson): string
    {
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://')) {
            return 'failed';
        }
        $serverPubRaw = null;
        $body = self::encryptPayload($p256dh, $auth, $payloadJson, null, null, $serverPubRaw);
        if ($body === null || $serverPubRaw === null) {
            return 'failed';
        }
        $origin = (string) parse_url($endpoint, PHP_URL_SCHEME) . '://' . (string) parse_url($endpoint, PHP_URL_HOST);
        $jwt = self::vapidJwt($origin);
        if ($jwt === null) {
            return 'failed';
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return 'failed';
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: WebPush ' . $jwt,
                'Crypto-Key: p256ecdsa=' . self::base64urlEncode($serverPubRaw),
                'Content-Type: application/octet-stream',
                'TTL: 60',
                'Urgency: normal',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($status === 404 || $status === 410) {
            return 'expired';
        }
        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        Logger::warning('push', "ارسال ناموفق ({$status}) {$curlError}");
        return 'failed';
    }
}
