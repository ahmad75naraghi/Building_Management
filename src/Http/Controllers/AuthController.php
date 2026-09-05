<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Utilities\JwtHelper;
use App\Utilities\Validator;
use App\Exceptions\ValidationException;
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

        $userService = new UserService();
        $user = $userService->authenticate($username, (string) ($data['password'] ?? ''));
        if (!$user) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'شماره موبایل یا رمز عبور اشتباه است.',
            ]);
        }

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
