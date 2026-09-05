<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ارسال پیامک از طریق پنل ملی‌پیامک (Melipayamak) با متد SendByBaseNumber.
 *
 * مشخصات از متغیرهای محیطی خوانده می‌شود تا در کد عمومی نماند؛
 * در صورت نبود، از مقادیر پیش‌فرض پنل استفاده می‌شود:
 *   MELIPAYAMAK_USERNAME / MELIPAYAMAK_PASSWORD / MELIPAYAMAK_BODY_ID
 *
 * در صورت نبود افزونه SOAP یا خطای شبکه، خطا فقط لاگ می‌شود
 * و مقدار false برمی‌گردد تا جریان اصلی (ثبت‌نام/دعوت) متوقف نشود.
 */
final class SmsService
{
    private string $username;
    private string $password;
    private int $bodyId;

    public function __construct()
    {
        $this->username = (string) (getenv('MELIPAYAMAK_USERNAME') ?: '9905367498');
        $this->password = (string) (getenv('MELIPAYAMAK_PASSWORD') ?: '96R3Q');
        $this->bodyId = (int) (getenv('MELIPAYAMAK_BODY_ID') ?: 530743);
    }

    /**
     * ارسال پیامک پترن (متن + آرگومان‌ها) به یک شماره.
     *
     * @param string $to   شماره مقصد (فرمت 09xxxxxxxxx)
     * @param string $text متن اصلی پیامک
     * @param array  $args آرگومان‌های پترن (متناظر با arg1 و arg2 و ...)
     */
    public function sendByBaseNumber(string $to, string $text, array $args = []): bool
    {
        $to = \App\Utilities\PhoneHelper::normalize($to);
        if (!\App\Utilities\PhoneHelper::isValid($to)) {
            error_log("[SmsService] invalid phone: {$to}");
            return false;
        }
        if (!class_exists(\SoapClient::class)) {
            error_log('[SmsService] php-soap extension is not installed; SMS skipped.');
            return false;
        }

        try {
            ini_set('soap.wsdl_cache_enabled', '0');
            /** @var \SoapClient $sms */
            $sms = new \SoapClient(
                'http://api.payamak-panel.com/post/Send.asmx?wsdl',
                ['encoding' => 'UTF-8', 'connection_timeout' => 15]
            );
            $data = [
                'username' => $this->username,
                'password' => $this->password,
                'text' => $text,
                'to' => $to,
                'bodyId' => $this->bodyId,
            ];
            // اگر پترن آرگومان دارد، به‌صورت آرایه ارسال شود
            if (!empty($args)) {
                $data['text'] = array_values($args);
            }
            $result = $sms->SendByBaseNumber($data)->SendByBaseNumberResult ?? null;
            if (is_string($result) && $result !== '' && !str_starts_with($result, '0')) {
                // پاسخ موفق ملی‌پیامک معمولاً شناسه ارسال (عدد مثبت) است
                return true;
            }
            error_log("[SmsService] send failed to {$to}: " . var_export($result, true));
            return is_string($result) && $result !== '';
        } catch (\Throwable $e) {
            error_log('[SmsService] exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * پیامک خوش‌آمد پس از ثبت‌نام.
     */
    public function sendWelcomeSms(string $to, string $name): bool
    {
        $text = "کاربر گرامی {$name}، ثبت‌نام شما در سامانه مدیریت ساختمان با موفقیت انجام شد.";
        return $this->sendByBaseNumber($to, $text);
    }

    /**
     * پیامک دعوت به ساختمان همراه با لینک پذیرش.
     */
    public function sendInviteSms(string $to, string $invitedName, string $buildingName, string $roleLabel, string $inviteLink): bool
    {
        $text = "کاربر گرامی {$invitedName}، شما به ساختمان «{$buildingName}» با نقش {$roleLabel} دعوت شدید. لینک پذیرش: {$inviteLink}";
        return $this->sendByBaseNumber($to, $text);
    }

    /**
     * پیامک یادآوری شارژ/بدهی (استفاده آینده در تسک خودکار ماهیانه).
     */
    public function sendChargeReminderSms(string $to, string $name, string $buildingName, string $amount): bool
    {
        $text = "کاربر گرامی {$name}، شارژ ماهیانه ساختمان «{$buildingName}» به مبلغ {$amount} تومان صادر شد. لطفاً پرداخت فرمایید.";
        return $this->sendByBaseNumber($to, $text);
    }
}
