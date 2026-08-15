<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\Invitation;
use App\Repositories\InvitationRepository;
use App\Utilities\Validator;

final class InvitationService
{
    public function __construct(private InvitationRepository $repo = new InvitationRepository())
    {
    }

    public function createInvitation(array $data, int $invitedBy): Invitation
    {
        $errors = Validator::validate($data, [
            'building_id' => 'required',
        ]);
        if (empty($data['invited_email']) && empty($data['invited_phone'])) {
            $errors['contact'] = 'Email or phone is required';
        }
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $invitation = new Invitation();
        $invitation->building_id = (int) $data['building_id'];
        $invitation->invited_email = $data['invited_email'] ?? null;
        $invitation->invited_phone = $data['invited_phone'] ?? null;
        $invitation->role = $data['role'] ?? 'resident';
        $invitation->token = bin2hex(random_bytes(32));
        $invitation->status = 'pending';
        $invitation->invited_by = $invitedBy;
        $invitation->expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

        $id = $this->repo->create($invitation);
        $invitation->id = $id;
        return $invitation;
    }

    public function acceptInvitation(string $token, int $userId): array
    {
        $invitation = $this->repo->findByToken($token);
        if (!$invitation) {
            throw new AppException('Invalid invitation token');
        }
        if ($invitation->status !== 'pending') {
            throw new AppException('Invitation already used or expired');
        }
        if ($invitation->expires_at && strtotime($invitation->expires_at) < time()) {
            throw new AppException('Invitation has expired');
        }

        $updated = $this->repo->updateStatus($token, 'accepted', date('Y-m-d H:i:s'));
        if (!$updated) {
            throw new AppException('Failed to accept invitation');
        }

        // Add user to building_members
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("
            INSERT IGNORE INTO building_members (user_id, building_id, role, status, invited_by, invitation_token)
            VALUES (?, ?, ?, 'active', ?, ?)
        ");
        $stmt->execute([
            $userId,
            $invitation->building_id,
            $invitation->role,
            $invitation->invited_by,
            $token,
        ]);

        return [
            'invitation' => $invitation->toArray(),
            'user_id' => $userId,
            'building_id' => $invitation->building_id,
        ];
    }

    public function listInvitations(int $buildingId): array
    {
        return $this->repo->findByBuildingId($buildingId);
    }
}
