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
        $cols = ['building_id', 'invited_email', 'invited_phone', 'role', 'unit_id', 'token', 'status', 'invited_by', 'expires_at'];
        $vals = [
            $invitation->building_id,
            $invitation->invited_email,
            $invitation->invited_phone,
            $invitation->role,
            $invitation->unit_id,
            $invitation->token,
            $invitation->status,
            $invitation->invited_by,
            $invitation->expires_at,
        ];
        if ($this->hasColumn('invited_name')) {
            $cols[] = 'invited_name';
            $vals[] = $invitation->invited_name;
        }
        $stmt = $db->prepare(
            'INSERT INTO invitations (' . implode(', ', $cols) . ') VALUES ('
                . implode(', ', array_fill(0, count($cols), '?')) . ')'
        );
        $stmt->execute($vals);
        return (int) $db->lastInsertId();
    }

    private function hasColumn(string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        try {
            $stmt = Database::getConnection()->prepare('SHOW COLUMNS FROM invitations LIKE ?');
            $stmt->execute([$column]);
            $cache[$column] = (bool) $stmt->fetch();
        } catch (\Exception $e) {
            $cache[$column] = false;
        }
        return $cache[$column];
    }

    public function findById(int $id): ?Invitation
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM invitations WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRow($row) : null;
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

    /** لغو دعوت‌نامه — فقط دعوت‌های در انتظار پذیرش لغو می‌شوند */
    public function revoke(int $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE invitations SET status = 'revoked' WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    private function mapRow(array $row): Invitation
    {
        $i = new Invitation();
        $i->id = (int) $row['id'];
        $i->building_id = (int) $row['building_id'];
        $i->invited_email = $row['invited_email'];
        $i->invited_phone = $row['invited_phone'];
        $i->invited_name = $row['invited_name'] ?? null;
        $i->role = $row['role'];
        $i->unit_id = isset($row['unit_id']) && $row['unit_id'] !== null ? (int) $row['unit_id'] : null;
        $i->token = $row['token'];
        $i->status = $row['status'];
        $i->invited_by = (int) $row['invited_by'];
        $i->expires_at = $row['expires_at'];
        $i->accepted_at = $row['accepted_at'];
        $i->created_at = $row['created_at'];
        return $i;
    }
}
