<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Config\AppConfig;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use App\Models\Invitation;
use App\Repositories\InvitationRepository;
use App\Utilities\PhoneHelper;
use App\Utilities\Validator;

final class InvitationService
{
    public function __construct(private InvitationRepository $repo = new InvitationRepository())
    {
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'manager' => 'مدیر',
            'owner' => 'مالک',
            'tenant' => 'مستأجر',
            'board' => 'هیئت مدیره',
            'accountant' => 'حسابدار',
            default => 'ساکن',
        };
    }

    public static function inviteLink(string $token): string
    {
        return AppConfig::getAppUrl() . '/invite.php?token=' . urlencode($token);
    }

    /**
     * ساخت دعوتنامه با نام + شماره موبایل + نقش + واحد، همراه با ارسال پیامک حاوی لینک.
     *
     * @return array{invitation: Invitation, sms_sent: bool}
     */
    public function createInvitation(array $data, int $invitedBy): array
    {
        $errors = Validator::validate($data, [
            'building_id' => 'required',
        ]);
        $name = trim((string) ($data['invited_name'] ?? ''));
        if ($name === '') {
            $errors['invited_name'] = 'نام و نام خانوادگی دعوت‌شونده الزامی است.';
        }
        $phone = PhoneHelper::normalize((string) ($data['invited_phone'] ?? ''));
        if (!PhoneHelper::isValid($phone)) {
            $errors['invited_phone'] = 'شماره موبایل معتبر نیست. مثال: 09123456789';
        }
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $invitation = new Invitation();
        $invitation->building_id = (int) $data['building_id'];
        $invitation->invited_email = null;
        $invitation->invited_phone = $phone;
        $invitation->invited_name = $name;
        $invitation->role = $data['role'] ?? 'resident';
        $invitation->unit_id = !empty($data['unit_id']) ? (int) $data['unit_id'] : null;
        $invitation->token = bin2hex(random_bytes(32));
        $invitation->status = 'pending';
        $invitation->invited_by = $invitedBy;
        $invitation->expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

        $id = $this->repo->create($invitation);
        $invitation->id = $id;

        // ارسال پیامک حاوی لینک دعوت
        $smsSent = false;
        try {
            $db = \App\Core\Database::getConnection();
            $stmt = $db->prepare("SELECT name FROM buildings WHERE id = ? LIMIT 1");
            $stmt->execute([$invitation->building_id]);
            $buildingName = (string) ($stmt->fetchColumn() ?: 'ساختمان');
            $smsSent = (new SmsService())->sendInviteSms(
                $phone,
                $name,
                $buildingName,
                self::roleLabel($invitation->role),
                self::inviteLink($invitation->token)
            );
        } catch (\Throwable $e) {
            Logger::error('InvitationService', 'ارسال پیامک دعوت ناموفق بود', [], $e);
        }

        return ['invitation' => $invitation, 'sms_sent' => $smsSent];
    }

    /**
     * ارسال مجدد پیامک دعوت برای یک دعوتنامه در انتظار.
     */
    public function resendSms(int $invitationId, int $buildingId): bool
    {
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM invitations WHERE id = ? AND building_id = ? LIMIT 1");
        $stmt->execute([$invitationId, $buildingId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || ($row['status'] ?? '') !== 'pending' || empty($row['invited_phone'])) {
            throw new AppException('دعوتنامه معتبر برای ارسال مجدد یافت نشد.');
        }
        $bstmt = $db->prepare("SELECT name FROM buildings WHERE id = ? LIMIT 1");
        $bstmt->execute([$buildingId]);
        $buildingName = (string) ($bstmt->fetchColumn() ?: 'ساختمان');
        return (new SmsService())->sendInviteSms(
            (string) $row['invited_phone'],
            (string) ($row['invited_name'] ?? 'کاربر گرامی'),
            $buildingName,
            self::roleLabel((string) ($row['role'] ?? 'resident')),
            self::inviteLink((string) $row['token'])
        );
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

        // اگر دعوتنامه برای واحد خاصی صادر شده باشد،
        // کاربر را به عنوان مالک یا مستاجر همان واحد ثبت می‌کنیم.
        if ($invitation->unit_id !== null) {
            $this->assignUserToUnit($invitation->unit_id, $userId, $invitation->role, $invitation->building_id);
        }

        return [
            'invitation' => $invitation->toArray(),
            'user_id' => $userId,
            'building_id' => $invitation->building_id,
        ];
    }

    /**
     * اتصال کاربر پذیرفته‌شده به واحد مشخص‌شده در دعوتنامه:
     *  - نقش owner → owner_user_id واحد
     *  - نقش tenant → tenant_user_id واحد (مالک قبلی واحد حفظ می‌شود)
     *  - سایر نقش‌ها → اتصال انجام نمی‌شود (فقط عضویت ساختمان)
     */
    private function assignUserToUnit(int $unitId, int $userId, string $role, int $buildingId): void
    {
        $db = \App\Core\Database::getConnection();

        // اطمینان از اینکه واحد متعلق به همان ساختمان است
        $stmt = $db->prepare("SELECT id FROM units WHERE id = ? AND building_id = ? LIMIT 1");
        $stmt->execute([$unitId, $buildingId]);
        if (!$stmt->fetchColumn()) {
            return;
        }

        if ($role === 'owner') {
            $db->prepare("UPDATE units SET owner_user_id = ? WHERE id = ?")->execute([$userId, $unitId]);
        } elseif ($role === 'tenant') {
            $db->prepare("UPDATE units SET tenant_user_id = ? WHERE id = ?")->execute([$userId, $unitId]);
        }
    }

    public function listInvitations(int $buildingId): array
    {
        return $this->repo->findByBuildingId($buildingId);
    }

    /**
     * اطلاعات دعوتنامه (بدون پذیرش) برای نمایش به کاربر پیش از پذیرش.
     */
    public function getInvitationInfo(string $token): ?array
    {
        $invitation = $this->repo->findByToken($token);
        if (!$invitation) {
            return null;
        }
        $db = \App\Core\Database::getConnection();
        $stmt = $db->prepare("SELECT name FROM buildings WHERE id = ? LIMIT 1");
        $stmt->execute([$invitation->building_id]);
        $buildingName = $stmt->fetchColumn();

        $unit = null;
        if ($invitation->unit_id !== null) {
            $stmt = $db->prepare("SELECT id, unit_number FROM units WHERE id = ? LIMIT 1");
            $stmt->execute([$invitation->unit_id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $unit = ['id' => (int) $row['id'], 'unit_number' => $row['unit_number']];
            }
        }

        return [
            'token' => $invitation->token,
            'building_id' => $invitation->building_id,
            'building_name' => $buildingName ?: 'نامشخص',
            'role' => $invitation->role,
            'role_label' => self::roleLabel($invitation->role),
            'unit' => $unit,
            'status' => $invitation->status,
            'expires_at' => $invitation->expires_at,
            'invited_email' => $invitation->invited_email,
            'invited_phone' => $invitation->invited_phone,
            'invited_name' => $invitation->invited_name,
        ];
    }
}
