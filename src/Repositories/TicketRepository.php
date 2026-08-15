<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Ticket;
use PDO;

final class TicketRepository
{
    public function create(Ticket $ticket): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO tickets (building_id, user_id, unit_id, category, is_anonymous, title, description, status, priority, assigned_to)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ticket->building_id,
            $ticket->user_id,
            $ticket->unit_id,
            (int) $ticket->is_anonymous,
            $ticket->category,
            $ticket->title,
            $ticket->description,
            $ticket->status,
            $ticket->priority,
            $ticket->assigned_to,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findById(int $id): ?Ticket
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRow($row) : null;
    }

    public function findByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM tickets WHERE building_id = ? ORDER BY created_at DESC");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findByUserId(int $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return array_map(fn($r) => $this->mapRow($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function updateStatus(int $id, string $status, ?int $assignedTo = null): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE tickets SET status = ?, assigned_to = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$status, $assignedTo, $id]);
    }

    private function mapRow(array $row): Ticket
    {
        $t = new Ticket();
        $t->id = (int) $row['id'];
        $t->building_id = (int) $row['building_id'];
        $t->user_id = (int) $row['user_id'];
        $t->unit_id = $row['unit_id'] ? (int) $row['unit_id'] : null;
        $t->category = $row['category'];
        $t->is_anonymous = (bool) $row['is_anonymous'];
        $t->title = $row['title'];
        $t->description = $row['description'];
        $t->status = $row['status'];
        $t->priority = $row['priority'];
        $t->assigned_to = $row['assigned_to'] ? (int) $row['assigned_to'] : null;
        $t->resolved_at = $row['resolved_at'];
        $t->created_at = $row['created_at'];
        $t->updated_at = $row['updated_at'];
        return $t;
    }
}
