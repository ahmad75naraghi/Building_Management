<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Utilities\JwtHelper;
use App\Utilities\Validator;
use App\Exceptions\ValidationException;
use App\Services\OtpService;
use App\Core\Logger;
use App\Services\RateLimiter;
use App\Services\UserService;

final class AuthController
{
    public function register(Request $request): Response
    {
        $data = $request->getJsonBody() ?? [];
        // سازگاری: اگر فقط email آمد و شبیه موبایل است، همان به‌عنوان phone استفاده می‌شود
        if (empty($data['phone']) && !empty($data['email']) && strpos((string) $data['email'], '@') === false) {
            $data['phone'] = $data['email'];
        }
        $errors = Validator::validate($data, [
            'name' => 'required',
            'phone' => 'required',
            'password' => 'required|min:6',
        ]);
        if (!empty($errors)) {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false,
                'errors' => $errors,
            ]);
        }

        $userService = new UserService();
        try {
            $user = $userService->register($data);
            $token = JwtHelper::generate([
                'sub' => $user->id,
                'phone' => $user->phone,
                'email' => $user->email,
                'name' => $user->name,
                'role' => 'user',
            ]);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user' => $user->toArray(),
                    'token' => $token,
                ],
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function login(Request $request): Response
    {
        $data = $request->getJsonBody() ?? [];
        // ورود با شماره موبایل (نام‌کاربری)؛ برای سازگاری email هم قبول است
        $username = trim((string) ($data['phone'] ?? $data['email'] ?? $data['username'] ?? ''));
        $errors = Validator::validate(
            ['username' => $username, 'password' => $data['password'] ?? null],
            ['username' => 'required', 'password' => 'required']
        );
        if (!empty($errors)) {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false,
                'errors' => $errors,
            ]);
        }

        // جلوگیری از حمله جست‌وجوی فراگیر روی رمز عبور
        $limiter = new RateLimiter();
        $rateKey = RateLimiter::loginKey($username);
        if ($limiter->tooManyAttempts($rateKey, RateLimiter::LOGIN_MAX_ATTEMPTS, RateLimiter::LOGIN_DECAY_SECONDS)) {
            $wait = $limiter->availableIn($rateKey, RateLimiter::LOGIN_DECAY_SECONDS);
            Logger::warning('AuthController', 'ورود به دلیل تلاش‌های مکرر مسدود شد', [
                'phone' => $username,
                'retry_after' => $wait,
            ]);
            return (new Response())->setStatusCode(429)->setJson([
                'success' => false,
                'message' => 'تلاش‌های ناموفق زیاد بود. لطفاً ' . max(1, (int) ceil($wait / 60)) . ' دقیقه دیگر تلاش کنید.',
                'data' => ['retry_after' => $wait],
            ]);
        }

        $userService = new UserService();
        $user = $userService->authenticate($username, (string) ($data['password'] ?? ''));
        if (!$user) {
            $limiter->hit($rateKey, RateLimiter::LOGIN_DECAY_SECONDS);
            $left = RateLimiter::LOGIN_MAX_ATTEMPTS
                - $limiter->attempts($rateKey, RateLimiter::LOGIN_DECAY_SECONDS);
            Logger::info('AuthController', 'ورود ناموفق با رمز عبور', [
                'phone' => $username,
                'attempts_left' => max(0, $left),
            ]);
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'شماره موبایل یا رمز عبور اشتباه است.',
            ]);
        }

        // ورود موفق: سابقه تلاش‌ها پاک می‌شود
        $limiter->clear($rateKey);

        $token = JwtHelper::generate([
            'sub' => $user->id,
            'phone' => $user->phone,
            'email' => $user->email,
            'name' => $user->name,
            'role' => 'user',
        ]);

        return (new Response())->setJson([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user->toArray(),
                'token' => $token,
            ],
        ]);
    }

    /**
     * گام ۱ ورود یکپارچه: فقط شماره موبایل.
     * پاسخ می‌گوید که باید رمز پرسیده شود یا کد یک‌بارمصرف ارسال گردد.
     */
    public function checkPhone(Request $request): Response
    {
        $data = $request->getJsonBody() ?? [];
        try {
            $status = (new UserService())->phoneStatus((string) ($data['phone'] ?? ''));
            return (new Response())->setJson([
                'success' => true,
                'data' => $status,
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ارسال کد یک‌بارمصرف به شماره موبایل.
     */
    public function sendOtp(Request $request): Response
    {
        $data = $request->getJsonBody() ?? [];
        try {
            $result = (new OtpService())->sendCode((string) ($data['phone'] ?? ''));
            if (!$result['sent']) {
                return (new Response())->setStatusCode(429)->setJson([
                    'success' => false,
                    'message' => 'کد قبلاً ارسال شده است. لطفاً کمی صبر کنید.',
                    'data' => ['retry_after' => $result['retry_after']],
                ]);
            }
            return (new Response())->setJson([
                'success' => true,
                'message' => 'کد تأیید پیامک شد.',
                'data' => [
                    'retry_after' => $result['retry_after'],
                    'debug_code' => $result['debug_code'],
                ],
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * تأیید کد یک‌بارمصرف: ورود یا ساخت حساب جدید.
     * پاسخ مشخص می‌کند که کاربر باید نام وارد کند یا رمز ست کند.
     */
    public function verifyOtp(Request $request): Response
    {
        $data = $request->getJsonBody() ?? [];
        $phone = (string) ($data['phone'] ?? '');
        $code = (string) ($data['code'] ?? '');

        try {
            (new OtpService())->verifyCode($phone, $code);

            $userService = new UserService();
            $result = $userService->loginOrCreateByPhone($phone);
            /** @var \App\Models\User $user */
            $user = $result['user'];

            $token = JwtHelper::generate([
                'sub' => $user->id,
                'phone' => $user->phone,
                'email' => $user->email,
                'name' => $user->name,
                'role' => 'user',
            ]);

            // گام بعدی: نام → رمز → پایان
            $next = 'done';
            if ($result['needs_name']) {
                $next = 'name';
            } elseif ($result['needs_password']) {
                $next = 'password';
            }

            return (new Response())->setJson([
                'success' => true,
                'message' => 'کد تأیید شد.',
                'data' => [
                    'user' => $user->toArray(),
                    'token' => $token,
                    'is_new' => $result['is_new'],
                    'needs_name' => $result['needs_name'],
                    'needs_password' => $result['needs_password'],
                    'next' => $next,
                ],
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * تکمیل نام و نام خانوادگی (پس از تأیید OTP).
     */
    public function completeName(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $user = (new UserService())->completeName($userId, (string) ($data['name'] ?? ''));
            // توکن جدید با نام به‌روزشده
            $token = JwtHelper::generate([
                'sub' => $user->id,
                'phone' => $user->phone,
                'email' => $user->email,
                'name' => $user->name,
                'role' => 'user',
            ]);
            return (new Response())->setJson([
                'success' => true,
                'message' => 'نام شما ثبت شد.',
                'data' => ['user' => $user->toArray(), 'token' => $token],
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ست‌کردن رمز عبور اولیه (کاربری که هنوز رمز ندارد).
     */
    public function setPassword(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            (new UserService())->setInitialPassword(
                $userId,
                (string) ($data['password'] ?? ''),
                (string) ($data['password_confirmation'] ?? '')
            );
            return (new Response())->setJson([
                'success' => true,
                'message' => 'رمز عبور شما با موفقیت تعیین شد.',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function refresh(Request $request): Response
    {
        $data = $request->getJsonBody() ?? [];
        $token = $data['token'] ?? null;
        if (!$token) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Refresh token required',
            ]);
        }

        try {
            $payload = JwtHelper::verify($token);
            $newToken = JwtHelper::generate([
                'sub' => $payload['sub'] ?? null,
                'email' => $payload['email'] ?? null,
                'name' => $payload['name'] ?? null,
                'role' => $payload['role'] ?? 'user',
            ]);
            return (new Response())->setJson([
                'success' => true,
                'token' => $newToken,
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Invalid refresh token',
            ]);
        }
    }

    public function logout(Request $request): Response
    {
        return (new Response())->setJson([
            'success' => true,
            'message' => 'Logged out successfully. Client should discard token.',
        ]);
    }

    public function me(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $user = (new \App\Repositories\UserRepository())->findById((int) $userId);
        if (!$user) {
            return (new Response())->setStatusCode(404)->setJson([
                'success' => false,
                'message' => 'User not found',
            ]);
        }
        return (new Response())->setJson([
            'success' => true,
            'data' => $user->toArray(),
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        $currentPassword = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['new_password'] ?? '');
        $newPasswordConfirmation = (string) ($data['password_confirmation'] ?? $data['new_password_confirmation'] ?? '');

        try {
            (new UserService())->changePassword(
                (int) $userId,
                $currentPassword,
                $newPassword,
                $newPasswordConfirmation
            );
            return (new Response())->setJson([
                'success' => true,
                'message' => 'رمز عبور با موفقیت تغییر کرد.',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function updateProfile(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $user = (new UserService())->updateProfile((int) $userId, $data);
            return (new Response())->setJson([
                'success' => true,
                'message' => 'مشخصات با موفقیت ویرایش شد.',
                'data' => $user->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
