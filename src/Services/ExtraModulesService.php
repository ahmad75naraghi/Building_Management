<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
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
use App\Repositories\ExtraModulesRepository;
use App\Utilities\Validator;

/**
 * Business logic for the Phase 6+ professional modules.
 *
 * Every write/read operation is scoped to a building and requires the
 * requesting user to be an active member of that building.
 */
final class ExtraModulesService
{
    public function __construct(
        private ExtraModulesRepository $repo = new ExtraModulesRepository(),
    ) {
    }

    // ------------------------------------------------------------------
    // Access control helpers
    // ------------------------------------------------------------------

    private function requireMember(int $userId, int $buildingId): void
    {
        if (!$this->repo->isBuildingMember($userId, $buildingId)) {
            throw new AppException('شما عضو این ساختمان نیستید.');
        }
    }

    private function buildingIdOrThrow(array $data, int $userId): int
    {
        $errors = Validator::validate($data, ['building_id' => 'required']);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }
        $buildingId = (int) $data['building_id'];
        $this->requireMember($userId, $buildingId);
        return $buildingId;
    }

    // ------------------------------------------------------------------
    // Bookings
    // ------------------------------------------------------------------

    public function createBooking(array $data, int $userId): Booking
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);

        $booking = new Booking();
        $booking->building_id = $buildingId;
        $booking->common_area_id = isset($data['common_area_id']) ? (int) $data['common_area_id'] : null;
        $booking->user_id = $userId;
        $booking->booking_date = $data['date'] ?? $data['booking_date'] ?? date('Y-m-d');
        $booking->start_time = $data['start_time'] ?? null;
        $booking->end_time = $data['end_time'] ?? null;
        $booking->status = $data['status'] ?? 'pending';

        $id = $this->repo->createBooking($booking);
        $booking->id = $id;

        // اعلان رزرو جدید به مدیر ساختمان (اگر رزروکننده خودش مدیر نباشد)
        $this->notifyManagerNewRecord($buildingId, $userId, 'رزرو جدید', 'رزرو جدیدی برای مشاعات ثبت شده و نیازمند بررسی است.', [
            'booking_id' => $id,
        ]);

        return $booking;
    }

    public function listBookings(int $buildingId, int $userId): array
    {
        $this->requireMember($userId, $buildingId);
        return $this->repo->findBookingsByBuildingId($buildingId);
    }

    // ------------------------------------------------------------------
    // Announcements
    // ------------------------------------------------------------------

    public function createAnnouncement(array $data, int $userId): Announcement
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);

        $errors = Validator::validate($data, [
            'title' => 'required',
            'content' => 'required',
        ]);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $announcement = new Announcement();
        $announcement->building_id = $buildingId;
        $announcement->title = $data['title'];
        $announcement->content = $data['content'];
        $announcement->is_pinned = (bool) ($data['is_pinned'] ?? false);
        $announcement->created_by = $userId;

        $id = $this->repo->createAnnouncement($announcement);
        $announcement->id = $id;

        // اعلان سراسری: همهٔ اعضای فعال ساختمان از اطلاعیهٔ جدید باخبر شوند
        // (ایجادکننده خودش مطلع است و اعلان نمی‌گیرد)
        try {
            (new NotificationService())->broadcastToBuilding(
                $buildingId,
                'announcement',
                '📢 اطلاعیه جدید: ' . $announcement->title,
                mb_substr((string) $announcement->content, 0, 160),
                ['announcement_id' => $id],
                [$userId]
            );
        } catch (\Throwable $e) {
            Logger::error('ExtraModules', 'اعلان اطلاعیهٔ جدید ارسال نشد', ['announcement_id' => $id], $e);
        }

        return $announcement;
    }

    public function listAnnouncements(int $buildingId, int $userId, int $limit = 50): array
    {
        $this->requireMember($userId, $buildingId);
        return $this->repo->findAnnouncementsByBuildingId($buildingId, $limit);
    }

    // ------------------------------------------------------------------
    // Maintenance requests
    // ------------------------------------------------------------------

    public function createMaintenanceRequest(array $data, int $userId): MaintenanceRequest
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);

        // Accept both `title` and `issue` keys (the documented API example uses `issue`)
        if (empty($data['title']) && empty($data['issue'])) {
            throw new ValidationException('title is required');
        }

        $request = new MaintenanceRequest();
        $request->building_id = $buildingId;
        $request->user_id = $userId;
        $request->title = !empty($data['title']) ? $data['title'] : ($data['issue'] ?? '');
        $request->description = $data['description'] ?? ($data['issue'] ?? null);
        $request->status = $data['status'] ?? 'pending';
        $request->assigned_technician_id = isset($data['assigned_technician_id']) ? (int) $data['assigned_technician_id'] : null;

        $id = $this->repo->createMaintenanceRequest($request);
        $request->id = $id;

        // اعلان درخواست تعمیرات جدید به مدیر ساختمان
        $this->notifyManagerNewRecord($buildingId, $userId, 'درخواست تعمیرات جدید', 'درخواست تعمیرات «' . $request->title . '» ثبت شد.', [
            'maintenance_id' => $id,
        ]);

        return $request;
    }

    public function listMaintenanceRequests(int $buildingId, int $userId, int $limit = 50): array
    {
        $this->requireMember($userId, $buildingId);
        return $this->repo->findMaintenanceByBuildingId($buildingId, $limit);
    }

    // ------------------------------------------------------------------
    // Votes
    // ------------------------------------------------------------------

    public function createVote(array $data, int $userId): Vote
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);
        $this->requireManager($userId, $buildingId);

        $errors = Validator::validate($data, ['title' => 'required']);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $vote = new Vote();
        $vote->building_id = $buildingId;
        $vote->title = $data['title'];
        $vote->description = $data['description'] ?? null;
        $vote->start_date = $data['start_date'] ?? date('Y-m-d H:i:s');
        $vote->end_date = $data['end_date'] ?? null;
        $vote->status = $data['status'] ?? 'active';
        $vote->created_by = $userId;

        $id = $this->repo->createVote($vote);
        $vote->id = $id;

        // در صورت ارسال گزینه‌ها همراه درخواست، بلافاصله ثبت می‌شوند
        if (!empty($data['options']) && is_array($data['options'])) {
            $this->repo->createVoteOptions($id, $data['options']);
        }

        \App\Core\Audit::log($userId, 'vote.create', 'vote', $id, $buildingId, [
            'title' => $vote->title,
        ]);

        // اعلان سراسری شروع رأی‌گیری برای همهٔ اعضای فعال
        try {
            (new NotificationService())->broadcastToBuilding(
                $buildingId,
                'vote',
                '🗳️ رأی‌گیری جدید: ' . $vote->title,
                'رأی‌گیری جدیدی در ساختمان شروع شده است. لطفاً نظر خود را ثبت کنید.',
                ['vote_id' => $id],
                [$userId]
            );
        } catch (\Throwable $e) {
            Logger::error('ExtraModules', 'اعلان رأی‌گیری جدید ارسال نشد', ['vote_id' => $id], $e);
        }

        return $this->enrichVote($vote, $userId);
    }

    public function listVotes(int $buildingId, int $userId): array
    {
        $this->requireMember($userId, $buildingId);
        $votes = $this->repo->findVotesByBuildingId($buildingId);
        return array_map(fn(Vote $vote) => $this->enrichVote($vote, $userId), $votes);
    }

    /**
     * افزودن گزینه به رأی‌گیری موجود (فقط تا وقتی رأی‌گیری باز است).
     *
     * @param array<int, string> $options
     */
    public function addVoteOptions(int $voteId, array $options, int $userId): Vote
    {
        $vote = $this->repo->findVoteById($voteId);
        if (!$vote) {
            throw new AppException('Vote not found');
        }
        $this->requireMember($userId, $vote->building_id);
        if ($vote->status !== 'active') {
            throw new AppException('رأی‌گیری بسته شده و گزینهٔ جدید نمی‌پذیرد.');
        }

        $clean = [];
        foreach ($options as $optionText) {
            $optionText = trim((string) $optionText);
            if ($optionText !== '') {
                $clean[] = $optionText;
            }
        }
        if (empty($clean)) {
            throw new ValidationException('options is required (non-empty strings)');
        }

        $this->repo->createVoteOptions($voteId, $clean);
        return $this->enrichVote($vote, $userId);
    }

    /**
     * ثبت رأی کاربر به یک گزینه. هر کاربر فقط یک بار می‌تواند رأی بدهد
     * (کلید یکتا vote_id + user_id در جدول vote_results).
     */
    public function castVote(int $voteId, int $optionId, int $userId): bool
    {
        $vote = $this->repo->findVoteById($voteId);
        if (!$vote) {
            throw new AppException('Vote not found');
        }
        $this->requireMember($userId, $vote->building_id);

        if ($vote->status !== 'active') {
            throw new AppException('رأی‌گیری بسته شده است.');
        }
        if ($vote->start_date && strtotime((string) $vote->start_date) > time()) {
            throw new AppException('رأی‌گیری هنوز شروع نشده است.');
        }
        if ($vote->end_date && strtotime((string) $vote->end_date) < time()) {
            throw new AppException('مهلت رأی‌گیری به پایان رسیده است.');
        }
        if (!$this->repo->optionBelongsToVote($optionId, $voteId)) {
            throw new AppException('Invalid vote option');
        }
        if ($this->repo->hasUserVoted($voteId, $userId)) {
            throw new AppException('شما قبلاً در این نظرسنجی رأی داده‌اید.');
        }

        $cast = $this->repo->castVote($voteId, $userId, $optionId);
        if ($cast) {
            \App\Core\Audit::log($userId, 'vote.cast', 'vote', $voteId, $vote->building_id, [
                'option_id' => $optionId,
            ]);
        }
        return $cast;
    }

    /**
     * نتیجه‌ی رأی‌گیری: گزینه‌ها + تعداد/درصد آراء + وضعیت رأی کاربر.
     *
     * @return array{
     *     vote: array,
     *     total_votes: int,
     *     options: array<int, array{option_id:int, option_text:string, votes_count:int, percentage:float}>,
     *     user_has_voted: bool,
     *     my_option_id: ?int
     * }
     */
    public function getVoteResults(int $voteId, int $userId): array
    {
        $vote = $this->repo->findVoteById($voteId);
        if (!$vote) {
            throw new AppException('Vote not found');
        }
        $this->requireMember($userId, $vote->building_id);

        $enriched = $this->enrichVote($vote, $userId);
        return [
            'vote' => $enriched->toArray(),
            'total_votes' => (int) ($enriched->results['total_votes'] ?? 0),
            'options' => $enriched->results['options'] ?? [],
            'user_has_voted' => $enriched->user_has_voted,
            'my_option_id' => $enriched->my_option_id,
        ];
    }

    /**
     * اتصال گزینه‌ها، نتایج و وضعیت رأی کاربر به یک رأی‌گیری.
     */
    private function enrichVote(Vote $vote, int $userId): Vote
    {
        $vote->options = array_map(
            fn(VoteOption $o) => $o->toArray(),
            $this->repo->findVoteOptionsByVoteId($vote->id)
        );

        $totalVotes = $this->repo->countVotesByVoteId($vote->id);
        $counts = $this->repo->findVoteResultsByVoteId($vote->id);
        $countByOption = [];
        foreach ($counts as $c) {
            $countByOption[$c['option_id']] = $c['votes_count'];
        }

        $optionsResult = [];
        foreach ($vote->options as $opt) {
            $votesCount = $countByOption[$opt['id']] ?? 0;
            $optionsResult[] = [
                'option_id' => $opt['id'],
                'option_text' => $opt['option_text'],
                'votes_count' => $votesCount,
                'percentage' => $totalVotes > 0 ? round(($votesCount / $totalVotes) * 100, 1) : 0.0,
            ];
        }

        $vote->results = [
            'total_votes' => $totalVotes,
            'options' => $optionsResult,
        ];
        $vote->user_has_voted = $this->repo->hasUserVoted($vote->id, $userId);
        $vote->my_option_id = $this->repo->findUserVoteOptionId($vote->id, $userId);
        return $vote;
    }

    // ------------------------------------------------------------------
    // Visitors
    // ------------------------------------------------------------------

    public function createVisitor(array $data, int $userId): Visitor
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);

        $errors = Validator::validate($data, ['visitor_name' => 'required']);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $visitor = new Visitor();
        $visitor->building_id = $buildingId;
        $visitor->user_id = $userId;
        $visitor->visitor_name = $data['visitor_name'];
        $visitor->visitor_car_plate = $data['visitor_car_plate'] ?? null;
        $visitor->visit_date = $data['visit_date'] ?? date('Y-m-d');
        $visitor->entry_time = $data['entry_time'] ?? date('H:i:s');
        $visitor->exit_time = $data['exit_time'] ?? null;
        $visitor->status = $data['status'] ?? 'entered';

        $id = $this->repo->createVisitor($visitor);
        $visitor->id = $id;
        return $visitor;
    }

    public function listVisitors(int $buildingId, int $userId): array
    {
        $this->requireMember($userId, $buildingId);
        return $this->repo->findVisitorsByBuildingId($buildingId);
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    /** ثبت/ویرایش/حذف اسناد ساختمان فقط با مدیر ساختمان است. */
    private function requireManager(int $userId, int $buildingId): void
    {
        if ($this->repo->memberRole($userId, $buildingId) !== 'manager') {
            throw new AppException('فقط مدیر ساختمان می‌تواند اسناد را مدیریت کند.');
        }
    }

    /**
     * ثبت سند با «لینک خارجی» (بدون آپلود فایل) — فقط مدیر.
     *
     * @param array<string, mixed> $data
     */
    public function createDocument(array $data, int $userId): Document
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);
        $this->requireManager($userId, $buildingId);

        $errors = Validator::validate($data, [
            'title' => 'required',
            'file_path' => 'required',
        ]);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $document = new Document();
        $document->building_id = $buildingId;
        $document->title = $data['title'];
        $document->file_path = $data['file_path'];
        $document->document_type = Document::normalizeCategory($data['document_type'] ?? null);
        $document->uploaded_by = $userId;
        $document->is_visible_to_members = self::flagFromInput($data['is_visible_to_members'] ?? 1);

        $id = $this->repo->createDocument($document);
        $document->id = $id;
        \App\Core\Audit::log($userId, 'document.create', 'document', $id, $buildingId, [
            'title' => $document->title, 'category' => $document->document_type, 'type' => 'link',
        ]);
        return $document;
    }

    /**
     * ثبت سند با آپلود فایل واقعی — فقط مدیر.
     * فایل با نام تصادفی و خارج از دسترس مستقیم وب ذخیره می‌شود.
     *
     * @param array<string, mixed> $meta building_id, title, document_type, is_visible_to_members
     */
    public function uploadDocument(array $meta, int $userId, string $fileContent, string $originalName): Document
    {
        $buildingId = $this->buildingIdOrThrow($meta, $userId);
        $this->requireManager($userId, $buildingId);

        $title = trim((string) ($meta['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException('title is required');
        }

        $stored = \App\Utilities\FileStorage::saveDocument($fileContent, $buildingId);

        $document = new Document();
        $document->building_id = $buildingId;
        $document->title = $title;
        // مسیر فیزیکی فقط برای مرجع داخلی؛ هرگز به کاربر نهایی نشان داده نمی‌شود
        $document->file_path = 'upload://' . $stored['stored_name'];
        $document->document_type = Document::normalizeCategory($meta['document_type'] ?? null);
        $document->uploaded_by = $userId;
        $document->stored_name = $stored['stored_name'];
        $document->mime_type = $stored['mime_type'];
        $document->file_size = $stored['file_size'];
        $document->is_visible_to_members = self::flagFromInput($meta['is_visible_to_members'] ?? 1);

        $id = $this->repo->createDocument($document);
        $document->id = $id;
        \App\Core\Audit::log($userId, 'document.create', 'document', $id, $buildingId, [
            'title' => $document->title, 'category' => $document->document_type, 'type' => 'file',
        ]);
        return $document;
    }

    /** تبدیل ورودی‌های مختلف (بولین/رشته/عدد) به پرچم ۰ یا ۱ */
    private static function flagFromInput(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        return ((int) $value) !== 0 ? 1 : 0;
    }

    /**
     * فهرست اسناد ساختمان بر اساس نقش:
     * مدیر همه اسناد (حتی غیرقابل رویت) و اعضا فقط اسناد قابل رویت را می‌بینند.
     */
    public function listDocuments(int $buildingId, int $userId): array
    {
        $this->requireMember($userId, $buildingId);
        $isManager = $this->repo->memberRole($userId, $buildingId) === 'manager';
        return $this->repo->findDocumentsByBuildingId($buildingId, $isManager);
    }

    /**
     * دریافت یک سند با کنترل رویت: اعضای عادی فقط اسناد قابل رویت را می‌بینند.
     */
    public function getDocumentForUser(int $documentId, int $userId): Document
    {
        $document = $this->repo->findDocumentById($documentId);
        if ($document === null) {
            throw new AppException('سند پیدا نشد.');
        }
        $this->requireMember($userId, $document->building_id);
        $isManager = $this->repo->memberRole($userId, $document->building_id) === 'manager';
        if (!$isManager && $document->is_visible_to_members !== 1) {
            throw new AppException('شما به این سند دسترسی ندارید.');
        }
        return $document;
    }

    /** تعویض فایل یک سند (فقط مدیر) — فایل قبلی از دیسک حذف می‌شود. */
    public function replaceDocumentFile(int $documentId, int $userId, string $fileContent, string $originalName): Document
    {
        $document = $this->repo->findDocumentById($documentId);
        if ($document === null) {
            throw new AppException('سند پیدا نشد.');
        }
        $this->requireManager($userId, $document->building_id);

        $stored = \App\Utilities\FileStorage::saveDocument($fileContent, $document->building_id);
        $this->repo->updateDocumentFile($documentId, $stored['stored_name'], $stored['mime_type'], $stored['file_size']);

        // حذف فایل قدیمی پس از موفقیت ذخیرهٔ فایل جدید
        if ($document->stored_name !== null) {
            try {
                $oldPath = \App\Utilities\FileStorage::documentPath($document->building_id, $document->stored_name);
                \App\Utilities\FileStorage::deleteFile($oldPath);
            } catch (\Throwable $e) {
                // فایل قدیمی وجود ندارد یا قابل حذف نیست؛ عملیات اصلی نباید شکست بخورد
                Logger::warning('Documents', 'حذف فایل قدیمی سند ناموفق بود', ['document_id' => $documentId, 'error' => $e->getMessage()]);
            }
        }

        $document->stored_name = $stored['stored_name'];
        $document->mime_type = $stored['mime_type'];
        $document->file_size = $stored['file_size'];
        \App\Core\Audit::log($userId, 'document.replace_file', 'document', $documentId, $document->building_id, [
            'title' => $document->title,
        ]);
        return $document;
    }

    // ------------------------------------------------------------------
    // Consumption readings
    // ------------------------------------------------------------------

    public function createConsumptionReading(array $data, int $userId): ConsumptionReading
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);

        // Accept both `amount` and `reading_value` keys (the documented API example uses `amount`)
        if (empty($data['amount']) && empty($data['reading_value'])) {
            throw new ValidationException('amount is required');
        }

        $reading = new ConsumptionReading();
        $reading->building_id = $buildingId;
        $reading->unit_id = isset($data['unit_id']) ? (int) $data['unit_id'] : null;
        $reading->consumption_type = $data['type'] ?? $data['consumption_type'] ?? 'electricity';
        $reading->reading_value = (float) (!empty($data['amount']) ? $data['amount'] : ($data['reading_value'] ?? 0));
        $reading->reading_date = $data['reading_date'] ?? date('Y-m-d');
        $reading->notes = $data['notes'] ?? null;
        $reading->created_by = $userId;

        $id = $this->repo->createConsumptionReading($reading);
        $reading->id = $id;
        return $reading;
    }

    public function listConsumptionReadings(int $buildingId, int $userId): array
    {
        $this->requireMember($userId, $buildingId);
        return $this->repo->findConsumptionByBuildingId($buildingId);
    }

    // ------------------------------------------------------------------
    // Emergency contacts
    // ------------------------------------------------------------------

    public function createEmergencyContact(array $data, int $userId): EmergencyContact
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);

        $errors = Validator::validate($data, [
            'name' => 'required',
            'phone' => 'required',
        ]);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $contact = new EmergencyContact();
        $contact->building_id = $buildingId;
        $contact->contact_name = $data['name'];
        $contact->contact_role = $data['role'] ?? $data['contact_role'] ?? null;
        $contact->phone = $data['phone'];
        $contact->email = $data['email'] ?? null;

        $id = $this->repo->createEmergencyContact($contact);
        $contact->id = $id;
        return $contact;
    }

    public function listEmergencyContacts(int $buildingId, int $userId): array
    {
        $this->requireMember($userId, $buildingId);
        return $this->repo->findEmergencyContactsByBuildingId($buildingId);
    }

    // ------------------------------------------------------------------
    // Meetings
    // ------------------------------------------------------------------

    public function createMeeting(array $data, int $userId): Meeting
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);

        $errors = Validator::validate($data, ['title' => 'required']);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $meeting = new Meeting();
        $meeting->building_id = $buildingId;
        $meeting->title = $data['title'];
        $meeting->description = $data['description'] ?? null;
        $meeting->meeting_date = $data['meeting_date'] ?? $data['date'] ?? date('Y-m-d H:i:s');
        $meeting->location = $data['location'] ?? null;
        $meeting->status = $data['status'] ?? 'scheduled';
        $meeting->created_by = $userId;

        $id = $this->repo->createMeeting($meeting);
        $meeting->id = $id;
        return $meeting;
    }

    public function listMeetings(int $buildingId, int $userId): array
    {
        $this->requireMember($userId, $buildingId);
        return $this->repo->findMeetingsByBuildingId($buildingId);
    }

    // ------------------------------------------------------------------
    // Reviews
    // ------------------------------------------------------------------

    public function createReview(array $data, int $userId): Review
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);

        $errors = Validator::validate($data, ['rating' => 'required']);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $rating = max(1, min(5, (int) ($data['rating'] ?? 5)));

        $review = new Review();
        $review->building_id = $buildingId;
        $review->user_id = $userId;
        $review->category_id = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $review->rating = $rating;
        $review->review_text = $data['comment'] ?? $data['review_text'] ?? null;

        $id = $this->repo->createReview($review);
        $review->id = $id;
        \App\Core\Audit::log($userId, 'review.create', 'review', $id, $buildingId, [
            'rating' => $review->rating,
        ]);
        return $review;
    }

    public function listReviews(int $buildingId, int $userId): array
    {
        $this->requireMember($userId, $buildingId);
        return $this->repo->findReviewsByBuildingId($buildingId);
    }

    // ------------------------------------------------------------------
    // Status updates & deletion (Phase 6+ modules)
    // ------------------------------------------------------------------

    /** وضعیت‌های مجاز هر ماژول */
    private const MODULE_STATUS_MAP = [
        'bookings' => ['pending', 'confirmed', 'cancelled', 'completed'],
        'maintenance' => ['pending', 'in_progress', 'resolved', 'closed'],
        'visitors' => ['entered', 'exited'],
        'votes' => ['active', 'closed'],
        'meetings' => ['scheduled', 'completed', 'cancelled'],
    ];

    public function updateEntityStatus(string $module, int $id, string $status, int $userId): bool
    {
        $allowed = self::MODULE_STATUS_MAP[$module] ?? null;
        if ($allowed === null) {
            throw new AppException('Status update is not supported for this module');
        }
        if (!in_array($status, $allowed, true)) {
            throw new ValidationException('Invalid status. Allowed: ' . implode(', ', $allowed));
        }

        $buildingId = $this->repo->getBuildingIdForModule($module, $id);
        if ($buildingId === null) {
            throw new AppException('Item not found');
        }
        $this->requireMember($userId, $buildingId);
        $this->requireCanModify($module, $id, $userId, $buildingId);

        \App\Core\Audit::log($userId, $module . '.status', $module, $id, $buildingId, [
            'status' => $status,
        ]);

        // خروج مهمان: علاوه بر وضعیت، زمان خروج هم ثبت می‌شود
        if ($module === 'visitors' && $status === 'exited') {
            return $this->repo->checkoutVisitor($id);
        }

        $updated = $this->repo->updateModuleStatus($module, $id, $status);

        // اعلان تغییر وضعیت رزرو/تعمیرات به ایجادکننده (اگر خودش تغییر نداد)
        if ($updated && in_array($module, ['bookings', 'maintenance'], true)) {
            $this->notifyModuleStatusChange($module, $id, $status, $userId, $buildingId);
        }

        return $updated;
    }

    /**
     * اعلان «رکورد جدید» به مدیر ساختمان — برای رزرو و درخواست تعمیرات.
     * اگر ایجادکننده خودش مدیر باشد، اعلانی فرستاده نمی‌شود.
     *
     * @param array<string, mixed> $data
     */
    private function notifyManagerNewRecord(int $buildingId, int $creatorId, string $title, string $message, array $data = []): void
    {
        $managerId = $this->buildingManagerId($buildingId);
        if ($managerId <= 0 || $managerId === $creatorId) {
            return;
        }
        try {
            (new NotificationService())->createNotification([
                'user_id' => $managerId,
                'building_id' => $buildingId,
                'notification_type' => 'general',
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('ExtraModules', 'اعلان رکورد جدید به مدیر ارسال نشد', [
                'building_id' => $buildingId,
            ], $e);
        }
    }

    /**
     * اعلان تغییر وضعیت رزرو/تعمیرات به ایجادکنندهٔ رکورد.
     */
    private function notifyModuleStatusChange(string $module, int $id, string $status, int $actorUserId, int $buildingId): void
    {
        $row = $this->repo->findModuleEntity($module, $id);
        if ($row === null) {
            return;
        }
        $ownerId = (int) ($row['user_id'] ?? 0);
        if ($ownerId <= 0 || $ownerId === $actorUserId) {
            return;
        }

        if ($module === 'bookings') {
            $labels = [
                'pending' => 'در انتظار تأیید است',
                'confirmed' => 'تأیید شد ✅',
                'cancelled' => 'لغو شد',
                'completed' => 'به پایان رسید',
            ];
            $subject = 'رزرو شما';
        } else {
            $labels = [
                'pending' => 'در انتظار بررسی است',
                'in_progress' => 'در حال انجام است',
                'resolved' => 'حل شد ✅',
                'closed' => 'بسته شد',
            ];
            $subject = 'درخواست تعمیرات «' . (string) ($row['title'] ?? '') . '»';
        }

        try {
            (new NotificationService())->createNotification([
                'user_id' => $ownerId,
                'building_id' => $buildingId,
                'notification_type' => 'general',
                'title' => 'به‌روزرسانی وضعیت',
                'message' => $subject . ' ' . ($labels[$status] ?? 'به‌روزرسانی شد') . '.',
                'data' => [$module === 'bookings' ? 'booking_id' : 'maintenance_id' => $id],
            ]);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('ExtraModules', 'اعلان تغییر وضعیت ارسال نشد', [
                'module' => $module,
                'id' => $id,
            ], $e);
        }
    }

    /** شناسهٔ مدیر ساختمان (اولین عضو فعال با نقش مدیر) */
    private function buildingManagerId(int $buildingId): int
    {
        $stmt = \App\Core\Database::getConnection()->prepare(
            "SELECT user_id FROM building_members
             WHERE building_id = ? AND role = 'manager' AND status = 'active'
             ORDER BY id LIMIT 1"
        );
        $stmt->execute([$buildingId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * ستون «سازنده رکورد» در هر ماژول (برای کنترل دسترسی ویرایش/حذف).
     */
    private const MODULE_OWNER_COLUMN = [
        'bookings' => 'user_id',
        'announcements' => 'created_by',
        'maintenance' => 'user_id',
        'votes' => 'created_by',
        'visitors' => 'user_id',
        'documents' => 'uploaded_by',
        'consumption' => 'created_by',
        'emergency-contacts' => null, // رکورد ساختمانی؛ فقط مدیر
        'meetings' => 'created_by',
        'reviews' => 'user_id',
    ];

    /**
     * ماژول‌هایی که فقط مدیر ساختمان اجازه ثبت/ویرایش/حذف دارد.
     */
    private const MANAGER_ONLY_MODULES = ['announcements', 'emergency-contacts', 'votes', 'meetings', 'documents'];

    /**
     * آیا کاربر اجازه ویرایش/حذف این رکورد را دارد؟
     *
     * قواعد (ساده و قابل پیش‌بینی):
     *   • مدیر ساختمان: به همه‌چیز دسترسی دارد.
     *   • سایر نقش‌ها (مالک/مستاجر/ساکن): فقط رکوردهایی که خودشان ثبت کرده‌اند.
     *   • ماژول‌های سطح ساختمان (اطلاعیه، تماس اضطراری، رأی‌گیری، جلسه، مدرک):
     *     فقط مدیر.
     */
    private function requireCanModify(string $module, int $id, int $userId, int $buildingId): void
    {
        $role = $this->repo->memberRole($userId, $buildingId);
        if ($role === null) {
            throw new AppException('شما عضو این ساختمان نیستید.');
        }
        if ($role === 'manager') {
            return;
        }

        if (in_array($module, self::MANAGER_ONLY_MODULES, true)) {
            throw new AppException('فقط مدیر ساختمان می‌تواند این مورد را تغییر دهد.');
        }

        $ownerColumn = self::MODULE_OWNER_COLUMN[$module] ?? null;
        if ($ownerColumn === null) {
            throw new AppException('فقط مدیر ساختمان می‌تواند این مورد را تغییر دهد.');
        }

        $row = $this->repo->findModuleEntity($module, $id);
        if ($row === null) {
            throw new AppException('Item not found');
        }
        if ((int) ($row[$ownerColumn] ?? 0) !== $userId) {
            throw new AppException('شما فقط می‌توانید مواردی را که خودتان ثبت کرده‌اید تغییر دهید.');
        }
    }

    /**
     * ویرایش عمومی رکوردهای ماژول‌ها (اطلاعیه، تعمیرات، مهمان، جلسه و ...).
     *
     * @param array<string, mixed> $data
     */
    public function updateEntity(string $module, int $id, array $data, int $userId): array
    {
        $buildingId = $this->repo->getBuildingIdForModule($module, $id);
        if ($buildingId === null) {
            throw new AppException('Item not found');
        }
        $this->requireMember($userId, $buildingId);
        $this->requireCanModify($module, $id, $userId, $buildingId);

        $allowed = $this->repo->editableColumns($module);
        if (empty($allowed)) {
            throw new AppException('Editing is not supported for this module');
        }

        // فقط کلیدهای مجاز و ارسال‌شده را نگه می‌داریم
        $payload = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $data)) {
                $value = $data[$column];
                if (is_bool($value)) {
                    $value = (int) $value;
                }
                if (is_string($value)) {
                    $value = trim($value);
                    if ($value === '') {
                        $value = null;
                    }
                }
                $payload[$column] = $value;
            }
        }
        if (empty($payload)) {
            throw new ValidationException('هیچ فیلد قابل ویرایشی ارسال نشده است.');
        }

        // فیلدهای اجباری نباید خالی شوند
        foreach (['title', 'content', 'visitor_name', 'contact_name', 'phone'] as $required) {
            if (array_key_exists($required, $payload) && ($payload[$required] === null || $payload[$required] === '')) {
                throw new ValidationException('فیلدهای اجباری نمی‌توانند خالی باشند.');
            }
        }

        $this->repo->updateModuleEntity($module, $id, $payload);

        \App\Core\Audit::log($userId, $module . '.update', $module, $id, $buildingId, []);

        $updated = $this->repo->findModuleEntity($module, $id);
        return $updated ?? [];
    }

    public function deleteEntity(string $module, int $id, int $userId): bool
    {
        $buildingId = $this->repo->getBuildingIdForModule($module, $id);
        if ($buildingId === null) {
            throw new AppException('Item not found');
        }
        $this->requireMember($userId, $buildingId);
        $this->requireCanModify($module, $id, $userId, $buildingId);

        // برای اسناد، پیش از حذف رکورد، مشخصات فایل فیزیکی را می‌خوانیم
        $storedDocument = $module === 'documents' ? $this->repo->findDocumentById($id) : null;

        $deleted = $this->repo->deleteModuleEntity($module, $id);

        \App\Core\Audit::log($userId, $module . '.delete', $module, $id, $buildingId, []);

        // حذف فایل سند از دیسک پس از حذف موفق رکورد
        if ($deleted && $storedDocument !== null && $storedDocument->stored_name !== null) {
            try {
                $path = \App\Utilities\FileStorage::documentPath($storedDocument->building_id, $storedDocument->stored_name);
                \App\Utilities\FileStorage::deleteFile($path);
            } catch (\Throwable $e) {
                // نبودن فایل روی دیسک نباید حذف رکورد را خراب گزارش کند
                Logger::warning('Documents', 'حذف فایل سند پس از حذف رکورد ناموفق بود', ['document_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $deleted;
    }

    // ------------------------------------------------------------------
    // Emergency alerts
    // ------------------------------------------------------------------

    public function createEmergencyAlert(array $data, int $userId): EmergencyAlert
    {
        $buildingId = $this->buildingIdOrThrow($data, $userId);

        $errors = Validator::validate($data, ['message' => 'required']);
        if (!empty($errors)) {
            throw new ValidationException(implode(', ', $errors));
        }

        $alert = new EmergencyAlert();
        $alert->building_id = $buildingId;
        $alert->alert_type = $data['alert_type'] ?? 'general';
        $alert->message = $data['message'];
        $alert->sent_by = $userId;

        $id = $this->repo->createEmergencyAlert($alert);
        $alert->id = $id;
        return $alert;
    }

    public function listEmergencyAlerts(int $buildingId, int $userId): array
    {
        $this->requireMember($userId, $buildingId);
        return $this->repo->findEmergencyAlertsByBuildingId($buildingId);
    }

    // ------------------------------------------------------------------
    // Meeting minutes
    // ------------------------------------------------------------------

    public function createMeetingMinute(int $meetingId, string $content, int $userId): MeetingMinute
    {
        $meetingBuildingId = $this->repo->getBuildingIdForModule('meetings', $meetingId);
        if ($meetingBuildingId === null) {
            throw new AppException('Meeting not found');
        }
        $this->requireMember($userId, $meetingBuildingId);

        if (trim($content) === '') {
            throw new ValidationException('minutes_content is required');
        }

        $minute = new MeetingMinute();
        $minute->meeting_id = $meetingId;
        $minute->minutes_content = trim($content);
        $minute->recorded_by = $userId;

        $id = $this->repo->createMeetingMinute($minute);
        $minute->id = $id;
        return $minute;
    }

    public function listMeetingMinutes(int $meetingId, int $userId): array
    {
        $meetingBuildingId = $this->repo->getBuildingIdForModule('meetings', $meetingId);
        if ($meetingBuildingId === null) {
            throw new AppException('Meeting not found');
        }
        $this->requireMember($userId, $meetingBuildingId);
        return $this->repo->findMeetingMinutesByMeetingId($meetingId);
    }

    // ------------------------------------------------------------------
    // Review categories
    // ------------------------------------------------------------------

    public function createReviewCategory(string $name): ReviewCategory
    {
        if (trim($name) === '') {
            throw new ValidationException('name is required');
        }
        $category = new ReviewCategory();
        $category->name = trim($name);
        $id = $this->repo->createReviewCategory($category);
        $category->id = $id;
        return $category;
    }

    public function listReviewCategories(): array
    {
        return $this->repo->findAllReviewCategories();
    }
}
