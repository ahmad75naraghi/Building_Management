<?php

declare(strict_types=1);

namespace App\Services;

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
            error_log('[UserService] welcome sms failed: ' . $e->getMessage());
        }

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
