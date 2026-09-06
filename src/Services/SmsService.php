<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
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
            Logger::warning('SmsService', 'شماره مقصد پیامک معتبر نیست', ['to' => $to]);
            return false;
        }
        if (!class_exists(\SoapClient::class)) {
            Logger::warning('SmsService', 'افزونه soap نصب نیست؛ ارسال پیامک انجام نشد', ['to' => $to]);
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
            Logger::error('SmsService', 'ارسال پیامک ناموفق بود', [
                'to' => $to,
                'provider_result' => is_scalar($result) ? (string) $result : gettype($result),
            ]);
            return is_string($result) && $result !== '';
        } catch (\Throwable $e) {
            Logger::error('SmsService', 'خطا در ارتباط با سامانه پیامک', ['to' => $to], $e);
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
     * پیامک کد یک‌بارمصرف ورود/ثبت‌نام.
     */
    public function sendOtpSms(string $to, string $code): bool
    {
        $minutes = (int) ceil(\App\Services\OtpService::TTL_SECONDS / 60);
        $text = "کد ورود شما به سامانه مدیریت ساختمان: {$code}\nاعتبار: {$minutes} دقیقه. این کد را در اختیار کسی قرار ندهید.";
        return $this->sendByBaseNumber($to, $text, [$code]);
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
