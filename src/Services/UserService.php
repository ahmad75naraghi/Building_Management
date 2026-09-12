<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Utilities\PhoneHelper;

final class UserService
{
    public function __construct(private UserRepository $repo = new UserRepository())
    {
    }

    /**
     * ثبت‌نام با نام، شماره موبایل (نام‌کاربری) و رمز عبور.
     * ایمیل اختیاری است و فقط برای سازگاری قدیمی نگه داشته شده.
     */
    public function register(array $data): User
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('نام و نام خانوادگی الزامی است.');
        }
        $phone = PhoneHelper::normalize((string) ($data['phone'] ?? ''));
        if (!PhoneHelper::isValid($phone)) {
            throw new ValidationException('شماره موبایل معتبر نیست. مثال: 09123456789');
        }
        if (mb_strlen((string) ($data['password'] ?? '')) < 6) {
            throw new ValidationException('رمز عبور باید حداقل ۶ کاراکتر باشد.');
        }
        if ($this->repo->findByPhone($phone)) {
            throw new ValidationException('این شماره موبایل قبلاً ثبت شده است. وارد شوید.');
        }

        $email = isset($data['email']) && trim((string) $data['email']) !== ''
            ? trim((string) $data['email'])
            : null;

        $user = new User();
        $user->name = $name;
        $user->email = $email;
        $user->phone = $phone;
        $user->password_hash = password_hash((string) $data['password'], PASSWORD_DEFAULT);

        $id = $this->repo->create($user);
        $user->id = $id;

        // پیامک خوش‌آمد (خطا در ارسال، ثبت‌نام را متوقف نمی‌کند)
        try {
            (new SmsService())->sendWelcomeSms($phone, $name);
        } catch (\Throwable $e) {
            Logger::error('UserService', 'ارسال پیامک خوش‌آمدگویی ناموفق بود', ['user_id' => $id], $e);
        }

        return $user;
    }

    /**
     * وضعیت یک شماره موبایل برای تصمیم‌گیری در صفحه ورود یکپارچه.
     *
     * @return array{exists:bool, has_password:bool, has_name:bool, next:string}
     *   next یکی از: password (رمز بخواه) | otp (کد بفرست)
     */
    public function phoneStatus(string $phone): array
    {
        $phone = PhoneHelper::normalize($phone);
        if (!PhoneHelper::isValid($phone)) {
            throw new ValidationException('شماره موبایل معتبر نیست. مثال: 09123456789');
        }

        $user = $this->repo->findByPhone($phone);
        $exists = $user !== null;
        $hasPassword = $exists && !empty($user->password_hash);
        $hasName = $exists && trim((string) $user->name) !== '';

        return [
            'exists' => $exists,
            'has_password' => $hasPassword,
            'has_name' => $hasName,
            // کاربر ثبت‌نام‌کرده با رمز → رمز بپرس؛ در غیر این صورت کد یک‌بارمصرف
            'next' => $hasPassword ? 'password' : 'otp',
        ];
    }

    /**
     * ورود/ثبت‌نام پس از تأیید موفق کد یک‌بارمصرف.
     *
     * اگر کاربر وجود نداشته باشد، یک رکورد بدون نام و بدون رمز ساخته می‌شود؛
     * تکمیل نام و ست‌کردن رمز در گام‌های بعدی انجام می‌گیرد.
     *
     * @return array{user:User, is_new:bool, needs_name:bool, needs_password:bool}
     */
    public function loginOrCreateByPhone(string $phone): array
    {
        $phone = PhoneHelper::normalize($phone);
        if (!PhoneHelper::isValid($phone)) {
            throw new ValidationException('شماره موبایل معتبر نیست.');
        }

        $user = $this->repo->findByPhone($phone);
        $isNew = false;

        if (!$user) {
            $user = new User();
            $user->phone = $phone;
            $user->name = null;
            $user->password_hash = null;
            $user->id = $this->repo->create($user);
            $isNew = true;
        }

        return [
            'user' => $user,
            'is_new' => $isNew,
            'needs_name' => trim((string) $user->name) === '',
            'needs_password' => empty($user->password_hash),
        ];
    }

    /**
     * ثبت نام و نام خانوادگی برای کاربری که تازه با OTP وارد شده است.
     */
    public function completeName(int $userId, string $name): User
    {
        $name = trim($name);
        if (mb_strlen($name) < 3) {
            throw new ValidationException('نام و نام خانوادگی را کامل وارد کنید.');
        }

        $user = $this->repo->findById($userId);
        if (!$user) {
            throw new AppException('کاربر یافت نشد.');
        }

        $this->repo->updateProfile($userId, $name, $user->phone);
        $user->name = $name;
        return $user;
    }

    /**
     * ست‌کردن رمز عبور برای کاربری که هنوز رمزی ندارد (پس از تأیید OTP).
     * برای تغییر رمزِ کاربری که رمز دارد، از changePassword استفاده کنید.
     */
    public function setInitialPassword(int $userId, string $password, string $confirmation): User
    {
        $user = $this->repo->findById($userId);
        if (!$user) {
            throw new AppException('کاربر یافت نشد.');
        }
        if (!empty($user->password_hash)) {
            throw new AppException('برای این حساب قبلاً رمز عبور تعیین شده است.');
        }
        if (mb_strlen($password) < 6) {
            throw new ValidationException('رمز عبور باید حداقل ۶ کاراکتر باشد.');
        }
        if ($password !== $confirmation) {
            throw new ValidationException('تکرار رمز عبور مطابقت ندارد.');
        }

        $this->repo->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));

        // پیامک خوش‌آمد پس از تکمیل ثبت‌نام
        try {
            (new SmsService())->sendWelcomeSms((string) $user->phone, (string) ($user->name ?: 'کاربر'));
        } catch (\Throwable $e) {
            Logger::error('UserService', 'ارسال پیامک خوش‌آمدگویی ناموفق بود', ['user_id' => $userId], $e);
        }

        $user->password_hash = 'set';
        return $user;
    }

    /**
     * ورود با شماره موبایل و رمز عبور.
     * برای سازگاری، ایمیل هم پذیرفته می‌شود.
     */
    public function authenticate(string $username, string $password): ?User
    {
        $username = trim($username);
        $user = null;
        if (str_contains($username, '@')) {
            $user = $this->repo->findByEmail($username);
        } else {
            $user = $this->repo->findByPhone($username);
            // اگر با موبایل پیدا نشد، ایمیل را هم امتحان کن (سازگاری قدیمی)
            if (!$user) {
                $user = $this->repo->findByEmail($username);
            }
        }
        if (!$user || !$user->password_hash) {
            return null;
        }
        if (!password_verify($password, $user->password_hash)) {
            return null;
        }
        return $user;
    }

    /**
     * تغییر رمز عبور کاربر لاگین‌شده.
     * ابتدا رمز فعلی بررسی می‌شود، سپس رمز جدید هش و ذخیره می‌گردد.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $newPasswordConfirmation): void
    {
        $user = $this->repo->findById($userId);
        if (!$user || !$user->password_hash) {
            throw new AppException('User not found');
        }
        if (!password_verify($currentPassword, $user->password_hash)) {
            throw new AppException('رمز عبور فعلی اشتباه است.');
        }
        if (mb_strlen($newPassword) < 6) {
            throw new ValidationException('رمز عبور جدید باید حداقل ۶ کاراکتر باشد.');
        }
        if ($newPassword !== $newPasswordConfirmation) {
            throw new ValidationException('تکرار رمز عبور جدید مطابقت ندارد.');
        }

        $this->repo->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
    }

    /**
     * ویرایش مشخصات کاربر (نام و شماره موبایل).
     * شماره موبایل نام‌کاربری است؛ تغییر آن با بررسی یکتا بودن انجام می‌شود.
     */
    public function updateProfile(int $userId, array $data): User
    {
        $user = $this->repo->findById($userId);
        if (!$user) {
            throw new AppException('User not found');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('نام و نام خانوادگی الزامی است.');
        }
        if (mb_strlen($name) > 100) {
            throw new ValidationException('نام نمی‌تواند بیشتر از ۱۰۰ کاراکتر باشد.');
        }

        $phone = null;
        if (isset($data['phone']) && trim((string) $data['phone']) !== '') {
            $phone = PhoneHelper::normalize((string) $data['phone']);
            if (!PhoneHelper::isValid($phone)) {
                throw new ValidationException('شماره موبایل معتبر نیست. مثال: 09123456789');
            }
            $existing = $this->repo->findByPhone($phone);
            if ($existing && (int) $existing->id !== $userId) {
                throw new ValidationException('این شماره موبایل قبلاً توسط کاربر دیگری ثبت شده است.');
            }
        } elseif ($user->phone) {
            $phone = $user->phone;
        }

        $this->repo->updateProfile($userId, $name, $phone);

        $updated = $this->repo->findById($userId);
        if (!$updated) {
            throw new AppException('User not found');
        }
        return $updated;
    }
}
