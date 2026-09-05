<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\UserRepository;

final class UserService
{
    public function __construct(private UserRepository $repo = new UserRepository())
    {
    }

    public function register(array $data): User
    {
        $user = new User();
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? null;
        $user->password_hash = password_hash($data['password'], PASSWORD_DEFAULT);

        $id = $this->repo->create($user);
        $user->id = $id;
        return $user;
    }

    public function authenticate(string $email, string $password): ?User
    {
        $user = $this->repo->findByEmail($email);
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
        if (mb_strlen($newPassword) < 8) {
            throw new ValidationException('رمز عبور جدید باید حداقل ۸ کاراکتر باشد.');
        }
        if ($newPassword !== $newPasswordConfirmation) {
            throw new ValidationException('تکرار رمز عبور جدید مطابقت ندارد.');
        }

        $this->repo->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
    }

    /**
     * ویرایش مشخصات کاربر (نام و شماره موبایل).
     * ایمیل قابل تغییر نیست (شناسه ورود است).
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
        $phone = isset($data['phone']) && $data['phone'] !== ''
            ? trim((string) $data['phone'])
            : null;
        if ($phone !== null && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) {
            throw new ValidationException('شماره موبایل معتبر نیست.');
        }

        $this->repo->updateProfile($userId, $name, $phone);

        $updated = $this->repo->findById($userId);
        if (!$updated) {
            throw new AppException('User not found');
        }
        return $updated;
    }
}
