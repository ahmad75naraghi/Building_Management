<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Announcement;
use App\Models\Booking;
use App\Models\ConsumptionReading;
use App\Models\Document;
use App\Models\EmergencyAlert;
use App\Models\EmergencyContact;
use App\Models\MaintenanceRequest;
use App\Models\Meeting;
use App\Models\MeetingMinute;
use App\Models\Review;
use App\Models\ReviewCategory;
use App\Models\Visitor;
use App\Models\Vote;
use App\Models\VoteOption;
use PDO;

/**
 * Data access layer for the Phase 6+ professional modules.
 *
 * Each module (bookings, announcements, maintenance, votes, visitors,
 * documents, consumption, emergency contacts, meetings, reviews) follows the
 * same insert + list-by-building pattern used across the codebase.
 */
final class ExtraModulesRepository
{
    // ------------------------------------------------------------------
    // Access control
    // ------------------------------------------------------------------

    public function isBuildingMember(int $userId, int $buildingId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id FROM building_members
             WHERE user_id = ? AND building_id = ? AND status = 'active'"
        );
        $stmt->execute([$userId, $buildingId]);
        return (bool) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Bookings
    // ------------------------------------------------------------------

    public function createBooking(Booking $booking): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO bookings (building_id, common_area_id, user_id, booking_date, start_time, end_time, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $booking->building_id,
            $booking->common_area_id,
            $booking->user_id,
            $booking->booking_date,
            $booking->start_time,
            $booking->end_time,
            $booking->status,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findBookingsByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM bookings WHERE building_id = ? ORDER BY booking_date DESC, created_at DESC");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapBooking($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapBooking(array $row): Booking
    {
        $b = new Booking();
        $b->id = (int) $row['id'];
        $b->building_id = (int) $row['building_id'];
        $b->common_area_id = $row['common_area_id'] !== null ? (int) $row['common_area_id'] : null;
        $b->user_id = (int) $row['user_id'];
        $b->booking_date = $row['booking_date'];
        $b->start_time = $row['start_time'];
        $b->end_time = $row['end_time'];
        $b->status = $row['status'];
        $b->created_at = $row['created_at'];
        return $b;
    }

    // ------------------------------------------------------------------
    // Announcements
    // ------------------------------------------------------------------

    public function createAnnouncement(Announcement $announcement): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO announcements (building_id, title, content, is_pinned, created_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $announcement->building_id,
            $announcement->title,
            $announcement->content,
            (int) $announcement->is_pinned,
            $announcement->created_by,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findAnnouncementsByBuildingId(int $buildingId, int $limit = 50): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM announcements WHERE building_id = ?
             ORDER BY is_pinned DESC, created_at DESC LIMIT " . (int) $limit
        );
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapAnnouncement($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapAnnouncement(array $row): Announcement
    {
        $a = new Announcement();
        $a->id = (int) $row['id'];
        $a->building_id = (int) $row['building_id'];
        $a->title = $row['title'];
        $a->content = $row['content'];
        $a->is_pinned = (bool) $row['is_pinned'];
        $a->created_by = (int) $row['created_by'];
        $a->created_at = $row['created_at'];
        return $a;
    }

    // ------------------------------------------------------------------
    // Maintenance requests
    // ------------------------------------------------------------------

    public function createMaintenanceRequest(MaintenanceRequest $request): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO maintenance_requests (building_id, user_id, title, description, status, assigned_technician_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $request->building_id,
            $request->user_id,
            $request->title,
            $request->description,
            $request->status,
            $request->assigned_technician_id,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findMaintenanceByBuildingId(int $buildingId, int $limit = 50): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM maintenance_requests WHERE building_id = ?
             ORDER BY created_at DESC LIMIT " . (int) $limit
        );
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapMaintenance($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapMaintenance(array $row): MaintenanceRequest
    {
        $m = new MaintenanceRequest();
        $m->id = (int) $row['id'];
        $m->building_id = (int) $row['building_id'];
        $m->user_id = (int) $row['user_id'];
        $m->title = $row['title'];
        $m->description = $row['description'];
        $m->status = $row['status'];
        $m->assigned_technician_id = $row['assigned_technician_id'] !== null ? (int) $row['assigned_technician_id'] : null;
        $m->created_at = $row['created_at'];
        return $m;
    }

    // ------------------------------------------------------------------
    // Votes
    // ------------------------------------------------------------------

    public function createVote(Vote $vote): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO votes (building_id, title, description, start_date, end_date, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $vote->building_id,
            $vote->title,
            $vote->description,
            $vote->start_date,
            $vote->end_date,
            $vote->status,
            $vote->created_by,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findVotesByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM votes WHERE building_id = ? ORDER BY start_date DESC");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapVote($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapVote(array $row): Vote
    {
        $v = new Vote();
        $v->id = (int) $row['id'];
        $v->building_id = (int) $row['building_id'];
        $v->title = $row['title'];
        $v->description = $row['description'];
        $v->start_date = $row['start_date'];
        $v->end_date = $row['end_date'];
        $v->status = $row['status'];
        $v->created_by = (int) $row['created_by'];
        return $v;
    }

    // ------------------------------------------------------------------
    // Visitors
    // ------------------------------------------------------------------

    public function createVisitor(Visitor $visitor): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO visitors (building_id, user_id, visitor_name, visitor_car_plate, visit_date, entry_time, exit_time, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $visitor->building_id,
            $visitor->user_id,
            $visitor->visitor_name,
            $visitor->visitor_car_plate,
            $visitor->visit_date,
            $visitor->entry_time,
            $visitor->exit_time,
            $visitor->status,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findVisitorsByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM visitors WHERE building_id = ? ORDER BY visit_date DESC, created_at DESC");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapVisitor($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapVisitor(array $row): Visitor
    {
        $v = new Visitor();
        $v->id = (int) $row['id'];
        $v->building_id = (int) $row['building_id'];
        $v->user_id = (int) $row['user_id'];
        $v->visitor_name = $row['visitor_name'];
        $v->visitor_car_plate = $row['visitor_car_plate'];
        $v->visit_date = $row['visit_date'];
        $v->entry_time = $row['entry_time'];
        $v->exit_time = $row['exit_time'];
        $v->status = $row['status'];
        $v->created_at = $row['created_at'];
        return $v;
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    public function createDocument(Document $document): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO documents (building_id, title, file_path, document_type, uploaded_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $document->building_id,
            $document->title,
            $document->file_path,
            $document->document_type,
            $document->uploaded_by,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findDocumentsByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM documents WHERE building_id = ? ORDER BY created_at DESC");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapDocument($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapDocument(array $row): Document
    {
        $d = new Document();
        $d->id = (int) $row['id'];
        $d->building_id = (int) $row['building_id'];
        $d->title = $row['title'];
        $d->file_path = $row['file_path'];
        $d->document_type = $row['document_type'];
        $d->uploaded_by = (int) $row['uploaded_by'];
        $d->created_at = $row['created_at'];
        return $d;
    }

    // ------------------------------------------------------------------
    // Consumption readings
    // ------------------------------------------------------------------

    public function createConsumptionReading(ConsumptionReading $reading): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO consumption_readings (building_id, unit_id, consumption_type, reading_value, reading_date, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $reading->building_id,
            $reading->unit_id,
            $reading->consumption_type,
            $reading->reading_value,
            $reading->reading_date,
            $reading->notes,
            $reading->created_by,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findConsumptionByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM consumption_readings WHERE building_id = ? ORDER BY reading_date DESC");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapConsumption($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapConsumption(array $row): ConsumptionReading
    {
        $c = new ConsumptionReading();
        $c->id = (int) $row['id'];
        $c->building_id = (int) $row['building_id'];
        $c->unit_id = $row['unit_id'] !== null ? (int) $row['unit_id'] : null;
        $c->consumption_type = $row['consumption_type'];
        $c->reading_value = (float) $row['reading_value'];
        $c->reading_date = $row['reading_date'];
        $c->notes = $row['notes'];
        $c->created_by = (int) $row['created_by'];
        return $c;
    }

    // ------------------------------------------------------------------
    // Emergency contacts
    // ------------------------------------------------------------------

    public function createEmergencyContact(EmergencyContact $contact): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO emergency_contacts (building_id, contact_name, contact_role, phone, email)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $contact->building_id,
            $contact->contact_name,
            $contact->contact_role,
            $contact->phone,
            $contact->email,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findEmergencyContactsByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM emergency_contacts WHERE building_id = ? ORDER BY contact_name");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapEmergencyContact($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapEmergencyContact(array $row): EmergencyContact
    {
        $e = new EmergencyContact();
        $e->id = (int) $row['id'];
        $e->building_id = (int) $row['building_id'];
        $e->contact_name = $row['contact_name'];
        $e->contact_role = $row['contact_role'];
        $e->phone = $row['phone'];
        $e->email = $row['email'];
        return $e;
    }

    // ------------------------------------------------------------------
    // Meetings
    // ------------------------------------------------------------------

    public function createMeeting(Meeting $meeting): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO meetings (building_id, title, description, meeting_date, location, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $meeting->building_id,
            $meeting->title,
            $meeting->description,
            $meeting->meeting_date,
            $meeting->location,
            $meeting->status,
            $meeting->created_by,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findMeetingsByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM meetings WHERE building_id = ? ORDER BY meeting_date DESC");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapMeeting($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapMeeting(array $row): Meeting
    {
        $m = new Meeting();
        $m->id = (int) $row['id'];
        $m->building_id = (int) $row['building_id'];
        $m->title = $row['title'];
        $m->description = $row['description'];
        $m->meeting_date = $row['meeting_date'];
        $m->location = $row['location'];
        $m->status = $row['status'];
        $m->created_by = (int) $row['created_by'];
        return $m;
    }

    // ------------------------------------------------------------------
    // Reviews
    // ------------------------------------------------------------------

    public function createReview(Review $review): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO reviews (building_id, user_id, category_id, rating, review_text)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $review->building_id,
            $review->user_id,
            $review->category_id,
            $review->rating,
            $review->review_text,
        ]);
        return (int) $db->lastInsertId();
    }

    public function findReviewsByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM reviews WHERE building_id = ? ORDER BY created_at DESC");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapReview($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapReview(array $row): Review
    {
        $r = new Review();
        $r->id = (int) $row['id'];
        $r->building_id = (int) $row['building_id'];
        $r->user_id = (int) $row['user_id'];
        $r->category_id = $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $r->rating = (int) $row['rating'];
        $r->review_text = $row['review_text'];
        $r->created_at = $row['created_at'];
        return $r;
    }

    // ------------------------------------------------------------------
    // Generic status/delete helpers (Phase 6+ modules)
    //
    // All module tables have a `building_id` column; membership is checked
    // in the service layer. Table names come from a fixed allowlist so the
    // dynamic SQL below is safe.
    // ------------------------------------------------------------------

    private const MODULE_TABLES = [
        'bookings' => 'bookings',
        'announcements' => 'announcements',
        'maintenance' => 'maintenance_requests',
        'votes' => 'votes',
        'visitors' => 'visitors',
        'documents' => 'documents',
        'consumption' => 'consumption_readings',
        'emergency-contacts' => 'emergency_contacts',
        'meetings' => 'meetings',
        'reviews' => 'reviews',
    ];

    public function getBuildingIdForModule(string $module, int $id): ?int
    {
        $table = self::MODULE_TABLES[$module] ?? null;
        if ($table === null) {
            return null;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT building_id FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $buildingId = $stmt->fetchColumn();
        return $buildingId !== false ? (int) $buildingId : null;
    }

    public function updateModuleStatus(string $module, int $id, string $status): bool
    {
        $table = self::MODULE_TABLES[$module] ?? null;
        if ($table === null) {
            return false;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE `{$table}` SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $id]);
    }

    public function checkoutVisitor(int $id): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "UPDATE visitors SET status = 'exited', exit_time = CURRENT_TIME WHERE id = ?"
        );
        return $stmt->execute([$id]);
    }

    public function deleteModuleEntity(string $module, int $id): bool
    {
        $table = self::MODULE_TABLES[$module] ?? null;
        if ($table === null) {
            return false;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM `{$table}` WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // ------------------------------------------------------------------
    // Voting (options + results)
    // ------------------------------------------------------------------

    /**
     * @param array<int, string> $options
     */
    public function createVoteOptions(int $voteId, array $options): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO vote_options (vote_id, option_text) VALUES (?, ?)");
        foreach ($options as $optionText) {
            $optionText = trim((string) $optionText);
            if ($optionText !== '') {
                $stmt->execute([$voteId, $optionText]);
            }
        }
    }

    /** @return array<int, VoteOption> */
    public function findVoteOptionsByVoteId(int $voteId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM vote_options WHERE vote_id = ? ORDER BY id ASC");
        $stmt->execute([$voteId]);
        return array_map(fn($r) => $this->mapVoteOption($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findVoteById(int $id): ?Vote
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM votes WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapVote($row) : null;
    }

    public function optionBelongsToVote(int $optionId, int $voteId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM vote_options WHERE id = ? AND vote_id = ? LIMIT 1");
        $stmt->execute([$optionId, $voteId]);
        return (bool) $stmt->fetchColumn();
    }

    public function hasUserVoted(int $voteId, int $userId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM vote_results WHERE vote_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$voteId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public function findUserVoteOptionId(int $voteId, int $userId): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT option_id FROM vote_results WHERE vote_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$voteId, $userId]);
        $optionId = $stmt->fetchColumn();
        return $optionId !== false ? (int) $optionId : null;
    }

    /**
     * Cast a vote. The unique key (vote_id, user_id) prevents double voting.
     */
    public function castVote(int $voteId, int $userId, int $optionId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO vote_results (vote_id, user_id, option_id) VALUES (?, ?, ?)"
        );
        return $stmt->execute([$voteId, $userId, $optionId]);
    }

    /**
     * @return array<int, array{option_id:int, votes_count:int}>
     */
    public function findVoteResultsByVoteId(int $voteId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT option_id, COUNT(*) AS votes_count
             FROM vote_results
             WHERE vote_id = ?
             GROUP BY option_id"
        );
        $stmt->execute([$voteId]);
        return array_map(
            fn($r) => ['option_id' => (int) $r['option_id'], 'votes_count' => (int) $r['votes_count']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function countVotesByVoteId(int $voteId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM vote_results WHERE vote_id = ?");
        $stmt->execute([$voteId]);
        return (int) $stmt->fetchColumn();
    }

    private function mapVoteOption(array $row): VoteOption
    {
        $o = new VoteOption();
        $o->id = (int) $row['id'];
        $o->vote_id = (int) $row['vote_id'];
        $o->option_text = $row['option_text'];
        return $o;
    }

    // ------------------------------------------------------------------
    // Emergency alerts
    // ------------------------------------------------------------------

    public function createEmergencyAlert(EmergencyAlert $alert): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO emergency_alerts (building_id, alert_type, message, sent_by)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $alert->building_id,
            $alert->alert_type,
            $alert->message,
            $alert->sent_by,
        ]);
        return (int) $db->lastInsertId();
    }

    /** @return array<int, EmergencyAlert> */
    public function findEmergencyAlertsByBuildingId(int $buildingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM emergency_alerts WHERE building_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([$buildingId]);
        return array_map(fn($r) => $this->mapEmergencyAlert($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapEmergencyAlert(array $row): EmergencyAlert
    {
        $a = new EmergencyAlert();
        $a->id = (int) $row['id'];
        $a->building_id = (int) $row['building_id'];
        $a->alert_type = $row['alert_type'];
        $a->message = $row['message'];
        $a->sent_by = (int) $row['sent_by'];
        $a->created_at = $row['created_at'];
        return $a;
    }

    // ------------------------------------------------------------------
    // Meeting minutes
    // ------------------------------------------------------------------

    public function createMeetingMinute(MeetingMinute $minute): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO meeting_minutes (meeting_id, minutes_content, recorded_by)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([
            $minute->meeting_id,
            $minute->minutes_content,
            $minute->recorded_by,
        ]);
        return (int) $db->lastInsertId();
    }

    /** @return array<int, MeetingMinute> */
    public function findMeetingMinutesByMeetingId(int $meetingId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM meeting_minutes WHERE meeting_id = ? ORDER BY created_at DESC");
        $stmt->execute([$meetingId]);
        return array_map(fn($r) => $this->mapMeetingMinute($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapMeetingMinute(array $row): MeetingMinute
    {
        $m = new MeetingMinute();
        $m->id = (int) $row['id'];
        $m->meeting_id = (int) $row['meeting_id'];
        $m->minutes_content = $row['minutes_content'];
        $m->recorded_by = (int) $row['recorded_by'];
        $m->created_at = $row['created_at'];
        return $m;
    }

    // ------------------------------------------------------------------
    // Review categories
    // ------------------------------------------------------------------

    public function createReviewCategory(ReviewCategory $category): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO review_categories (name) VALUES (?)");
        $stmt->execute([$category->name]);
        return (int) $db->lastInsertId();
    }

    /** @return array<int, ReviewCategory> */
    public function findAllReviewCategories(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM review_categories ORDER BY name ASC");
        $stmt->execute();
        return array_map(fn($r) => $this->mapReviewCategory($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapReviewCategory(array $row): ReviewCategory
    {
        $c = new ReviewCategory();
        $c->id = (int) $row['id'];
        $c->name = $row['name'];
        return $c;
    }
}
