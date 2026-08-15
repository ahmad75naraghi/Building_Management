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
        $errors = Validator::validate($data, [
            'name' => 'required',
            'email' => 'required|email',
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
        $errors = Validator::validate($data, [
            'email' => 'required|email',
            'password' => 'required',
        ]);
        if (!empty($errors)) {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false,
                'errors' => $errors,
            ]);
        }

        $userService = new UserService();
        $user = $userService->authenticate($data['email'], $data['password']);
        if (!$user) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Invalid credentials',
            ]);
        }

        $token = JwtHelper::generate([
            'sub' => $user->id,
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
}
