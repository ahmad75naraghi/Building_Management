<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Invitation;
use PDO;

final class InvitationRepository
{
    public function create(Invitation $invitation): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO invitations (building_id, invited_email, invited_phone, role, token, status, invited_by, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invitation->building_id,
            $invitation->invited_email,
            $invitation->invited_phone,
            $invitation->role,
            $invitation->token,
            $invitation->status,
            $invitation->invited_by,
            $invitation->expires_at,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findByToken(string $token): ?Invitation
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM invitations WHERE token = ?");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRow($row) : null;
    }

    public function findByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM invitations WHERE building_id = ? ORDER BY created_at DESC");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function updateStatus(string $token, string $status, ?string $acceptedAt = null): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE invitations SET status = ?, accepted_at = ? WHERE token = ?");
        return $stmt->execute([$status, $acceptedAt, $token]);
    }

    private function mapRow(array $row): Invitation
    {
        $i = new Invitation();
        $i->id = (int) $row['id'];
        $i->building_id = (int) $row['building_id'];
        $i->invited_email = $row['invited_email'];
        $i->invited_phone = $row['invited_phone'];
        $i->role = $row['role'];
        $i->token = $row['token'];
        $i->status = $row['status'];
        $i->invited_by = (int) $row['invited_by'];
        $i->expires_at = $row['expires_at'];
        $i->accepted_at = $row['accepted_at'];
        $i->created_at = $row['created_at'];
        return $i;
    }
}
