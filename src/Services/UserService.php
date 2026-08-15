<?php

declare(strict_types=1);

namespace App\Services;

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
}
