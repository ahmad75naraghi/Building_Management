<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\User;
use PDO;

final class UserRepository
{
    public function create(User $user): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO users (name, email, phone, password_hash)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $user->name,
            $user->email,
            $user->phone,
            $user->password_hash,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findByEmail(string $email): ?User
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $this->mapRow($row);
    }

    public function findById(int $id): ?User
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRow($row) : null;
    }

    private function mapRow(array $row): User
    {
        $user = new User();
        $user->id = (int) $row['id'];
        $user->name = $row['name'];
        $user->email = $row['email'];
        $user->phone = $row['phone'];
        $user->password_hash = $row['password_hash'];
        $user->created_at = $row['created_at'];
        return $user;
    }
}
