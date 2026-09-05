<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ExtraModulesService;

final class ExtraModulesController
{
    private ExtraModulesService $service;

    public function __construct()
    {
        $this->service = new ExtraModulesService();
    }

    private function userIdOrReject(Request $request): ?int
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return null;
        }
        return (int) $userId;
    }

    private function buildingIdOrReject(Request $request): ?int
    {
        $buildingId = (int) ($request->getQueryParam('building_id') ?? 0);
        return $buildingId > 0 ? $buildingId : null;
    }

    private function storeEntity(Request $request, string $method, string $successMessage): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $entity = $this->service->$method($data, $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => $successMessage,
                'data' => $entity->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function listEntities(Request $request, string $method): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $buildingId = $this->buildingIdOrReject($request);
        if (!$buildingId) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'building_id query parameter is required',
            ]);
        }
        try {
            $items = $this->service->$method($buildingId, $userId);
            return (new Response())->setJson([
                'success' => true,
                'data' => array_map(fn($item) => $item->toArray(), $items),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Bookings
    // ------------------------------------------------------------------

    public function storeBooking(Request $request): Response
    {
        return $this->storeEntity($request, 'createBooking', 'Booking created');
    }

    public function indexBookings(Request $request): Response
    {
        return $this->listEntities($request, 'listBookings');
    }

    // ------------------------------------------------------------------
    // Announcements
    // ------------------------------------------------------------------

    public function storeAnnouncement(Request $request): Response
    {
        return $this->storeEntity($request, 'createAnnouncement', 'Announcement created');
    }

    public function indexAnnouncements(Request $request): Response
    {
        return $this->listEntities($request, 'listAnnouncements');
    }

    // ------------------------------------------------------------------
    // Maintenance
    // ------------------------------------------------------------------

    public function storeMaintenance(Request $request): Response
    {
        return $this->storeEntity($request, 'createMaintenanceRequest', 'Maintenance request created');
    }

    public function indexMaintenance(Request $request): Response
    {
        return $this->listEntities($request, 'listMaintenanceRequests');
    }

    // ------------------------------------------------------------------
    // Votes
    // ------------------------------------------------------------------

    public function storeVote(Request $request): Response
    {
        return $this->storeEntity($request, 'createVote', 'Vote created');
    }

    public function indexVotes(Request $request): Response
    {
        return $this->listEntities($request, 'listVotes');
    }

    // ------------------------------------------------------------------
    // Visitors
    // ------------------------------------------------------------------

    public function storeVisitor(Request $request): Response
    {
        return $this->storeEntity($request, 'createVisitor', 'Visitor registered');
    }

    public function indexVisitors(Request $request): Response
    {
        return $this->listEntities($request, 'listVisitors');
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    public function storeDocument(Request $request): Response
    {
        return $this->storeEntity($request, 'createDocument', 'Document uploaded');
    }

    public function indexDocuments(Request $request): Response
    {
        return $this->listEntities($request, 'listDocuments');
    }

    // ------------------------------------------------------------------
    // Consumption
    // ------------------------------------------------------------------

    public function storeConsumption(Request $request): Response
    {
        return $this->storeEntity($request, 'createConsumptionReading', 'Consumption reading saved');
    }

    public function indexConsumption(Request $request): Response
    {
        return $this->listEntities($request, 'listConsumptionReadings');
    }

    // ------------------------------------------------------------------
    // Emergency contacts
    // ------------------------------------------------------------------

    public function storeEmergencyContact(Request $request): Response
    {
        return $this->storeEntity($request, 'createEmergencyContact', 'Emergency contact saved');
    }

    public function indexEmergencyContacts(Request $request): Response
    {
        return $this->listEntities($request, 'listEmergencyContacts');
    }

    // ------------------------------------------------------------------
    // Meetings
    // ------------------------------------------------------------------

    public function storeMeeting(Request $request): Response
    {
        return $this->storeEntity($request, 'createMeeting', 'Meeting scheduled');
    }

    public function indexMeetings(Request $request): Response
    {
        return $this->listEntities($request, 'listMeetings');
    }

    // ------------------------------------------------------------------
    // Reviews
    // ------------------------------------------------------------------

    public function storeReview(Request $request): Response
    {
        return $this->storeEntity($request, 'createReview', 'Review submitted');
    }

    public function indexReviews(Request $request): Response
    {
        return $this->listEntities($request, 'listReviews');
    }

    // ------------------------------------------------------------------
    // Voting: options, casting, results
    // ------------------------------------------------------------------

    public function addVoteOptions(Request $request): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $voteId = (int) ($request->getAttribute('vote_id') ?? 0);
        if ($voteId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'vote_id is required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        $options = $data['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }
        try {
            $vote = $this->service->addVoteOptions($voteId, $options, $userId);
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Options added',
                'data' => $vote->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    public function castVote(Request $request): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $voteId = (int) ($request->getAttribute('vote_id') ?? 0);
        $data = $request->getJsonBody() ?? [];
        $optionId = (int) ($data['option_id'] ?? 0);
        if ($voteId <= 0 || $optionId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'vote_id and option_id are required',
            ]);
        }
        try {
            $this->service->castVote($voteId, $optionId, $userId);
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Your vote has been recorded',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    public function voteResults(Request $request): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $voteId = (int) ($request->getAttribute('vote_id') ?? 0);
        if ($voteId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'vote_id is required',
            ]);
        }
        try {
            $results = $this->service->getVoteResults($voteId, $userId);
            return (new Response())->setJson([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Generic status update + delete helpers
    // ------------------------------------------------------------------

    private function changeEntityStatus(Request $request, string $module, array $allowed): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $id = (int) ($request->getAttribute('id') ?? 0);
        $data = $request->getJsonBody() ?? [];
        $status = trim((string) ($data['status'] ?? ''));
        if ($id <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'id is required',
            ]);
        }
        if ($status === '' || !in_array($status, $allowed, true)) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'Invalid status. Allowed: ' . implode(', ', $allowed),
            ]);
        }
        try {
            $updated = $this->service->updateEntityStatus($module, $id, $status, $userId);
            return (new Response())->setJson([
                'success' => $updated,
                'message' => $updated ? 'Status updated' : 'Failed to update status',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    private function destroyEntity(Request $request, string $module): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $id = (int) ($request->getAttribute('id') ?? 0);
        if ($id <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'id is required',
            ]);
        }
        try {
            $deleted = $this->service->deleteEntity($module, $id, $userId);
            return (new Response())->setJson([
                'success' => $deleted,
                'message' => $deleted ? 'Item deleted' : 'Failed to delete item',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // --- Bookings ---

    public function updateBookingStatus(Request $request): Response
    {
        return $this->changeEntityStatus($request, 'bookings', ['pending', 'confirmed', 'cancelled', 'completed']);
    }

    public function destroyBooking(Request $request): Response
    {
        return $this->destroyEntity($request, 'bookings');
    }

    // --- Announcements ---

    public function destroyAnnouncement(Request $request): Response
    {
        return $this->destroyEntity($request, 'announcements');
    }

    // --- Maintenance ---

    public function updateMaintenanceStatus(Request $request): Response
    {
        return $this->changeEntityStatus($request, 'maintenance', ['pending', 'in_progress', 'resolved', 'closed']);
    }

    public function destroyMaintenance(Request $request): Response
    {
        return $this->destroyEntity($request, 'maintenance');
    }

    // --- Visitors ---

    public function visitorCheckout(Request $request): Response
    {
        return $this->changeEntityStatus($request, 'visitors', ['entered', 'exited']);
    }

    public function destroyVisitor(Request $request): Response
    {
        return $this->destroyEntity($request, 'visitors');
    }

    // --- Documents ---

    public function destroyDocument(Request $request): Response
    {
        return $this->destroyEntity($request, 'documents');
    }

    // --- Consumption ---

    public function destroyConsumption(Request $request): Response
    {
        return $this->destroyEntity($request, 'consumption');
    }

    // --- Emergency contacts ---

    public function destroyEmergencyContact(Request $request): Response
    {
        return $this->destroyEntity($request, 'emergency-contacts');
    }

    // --- Meetings ---

    public function updateMeetingStatus(Request $request): Response
    {
        return $this->changeEntityStatus($request, 'meetings', ['scheduled', 'completed', 'cancelled']);
    }

    public function destroyMeeting(Request $request): Response
    {
        return $this->destroyEntity($request, 'meetings');
    }

    // --- Reviews ---

    public function destroyReview(Request $request): Response
    {
        return $this->destroyEntity($request, 'reviews');
    }

    // --- Votes ---

    public function updateVoteStatus(Request $request): Response
    {
        return $this->changeEntityStatus($request, 'votes', ['active', 'closed']);
    }

    public function destroyVote(Request $request): Response
    {
        return $this->destroyEntity($request, 'votes');
    }

    // ------------------------------------------------------------------
    // Emergency alerts
    // ------------------------------------------------------------------

    public function storeEmergencyAlert(Request $request): Response
    {
        return $this->storeEntity($request, 'createEmergencyAlert', 'Emergency alert sent');
    }

    public function indexEmergencyAlerts(Request $request): Response
    {
        return $this->listEntities($request, 'listEmergencyAlerts');
    }

    // ------------------------------------------------------------------
    // Meeting minutes
    // ------------------------------------------------------------------

    public function storeMeetingMinute(Request $request): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $meetingId = (int) ($request->getAttribute('meeting_id') ?? 0);
        if ($meetingId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'meeting_id is required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        $content = (string) ($data['minutes_content'] ?? $data['content'] ?? '');
        try {
            $minute = $this->service->createMeetingMinute($meetingId, $content, $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Meeting minutes recorded',
                'data' => $minute->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    public function indexMeetingMinutes(Request $request): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $meetingId = (int) ($request->getAttribute('meeting_id') ?? 0);
        if ($meetingId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'meeting_id is required',
            ]);
        }
        try {
            $minutes = $this->service->listMeetingMinutes($meetingId, $userId);
            return (new Response())->setJson([
                'success' => true,
                'data' => array_map(fn($m) => $m->toArray(), $minutes),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Review categories
    // ------------------------------------------------------------------

    public function storeReviewCategory(Request $request): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        $name = (string) ($data['name'] ?? '');
        try {
            $category = $this->service->createReviewCategory($name);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Review category created',
                'data' => $category->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    public function indexReviewCategories(Request $request): Response
    {
        $userId = $this->userIdOrReject($request);
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        try {
            $categories = $this->service->listReviewCategories();
            return (new Response())->setJson([
                'success' => true,
                'data' => array_map(fn($c) => $c->toArray(), $categories),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }
}
