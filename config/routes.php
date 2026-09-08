<?php

declare(strict_types=1);

namespace App\Config;

final class Routes
{
    public static array $routes = [
        // Auth
        'POST /api/auth/register' => ['App\Http\Controllers\AuthController', 'register'],
        'POST /api/auth/login' => ['App\Http\Controllers\AuthController', 'login'],
        'POST /api/auth/check-phone' => ['App\Http\Controllers\AuthController', 'checkPhone'],
        'POST /api/auth/send-otp' => ['App\Http\Controllers\AuthController', 'sendOtp'],
        'POST /api/auth/verify-otp' => ['App\Http\Controllers\AuthController', 'verifyOtp'],
        'POST /api/auth/complete-name' => ['App\Http\Controllers\AuthController', 'completeName'],
        'POST /api/auth/set-password' => ['App\Http\Controllers\AuthController', 'setPassword'],
        'POST /api/auth/refresh' => ['App\Http\Controllers\AuthController', 'refresh'],
        'POST /api/auth/logout' => ['App\Http\Controllers\AuthController', 'logout'],
        'GET /api/auth/me' => ['App\Http\Controllers\AuthController', 'me'],
        'PUT /api/auth/me' => ['App\Http\Controllers\AuthController', 'updateProfile'],
        'PUT /api/auth/password' => ['App\Http\Controllers\AuthController', 'changePassword'],

        // Building
        'GET /api/buildings' => ['App\Http\Controllers\BuildingController', 'index'],
        'POST /api/buildings' => ['App\Http\Controllers\BuildingController', 'store'],
        'GET /api/buildings/{id}' => ['App\Http\Controllers\BuildingController', 'show'],
        'PUT /api/buildings/{id}' => ['App\Http\Controllers\BuildingController', 'update'],
        'DELETE /api/buildings/{id}' => ['App\Http\Controllers\BuildingController', 'destroy'],

        // Building Hierarchy (Dynamic)
        'GET /api/buildings/{building_id}/hierarchy/settings' => ['App\Http\Controllers\BuildingController', 'hierarchySettings'],
        'PUT /api/buildings/{building_id}/hierarchy/settings' => ['App\Http\Controllers\BuildingController', 'updateHierarchySettings'],

        // Blocks
        'POST /api/buildings/{building_id}/blocks' => ['App\Http\Controllers\BuildingController', 'storeBlock'],
        'GET /api/buildings/{building_id}/blocks' => ['App\Http\Controllers\BuildingController', 'indexBlocks'],
        'PUT /api/blocks/{id}' => ['App\Http\Controllers\BuildingController', 'updateBlock'],
        'DELETE /api/blocks/{id}' => ['App\Http\Controllers\BuildingController', 'destroyBlock'],

        // Floors
        'POST /api/buildings/{building_id}/floors' => ['App\Http\Controllers\BuildingController', 'storeFloor'],
        'GET /api/buildings/{building_id}/floors' => ['App\Http\Controllers\BuildingController', 'indexFloors'],
        'PUT /api/floors/{id}' => ['App\Http\Controllers\BuildingController', 'updateFloor'],
        'DELETE /api/floors/{id}' => ['App\Http\Controllers\BuildingController', 'destroyFloor'],

        // Units
        'POST /api/buildings/{building_id}/units' => ['App\Http\Controllers\BuildingController', 'storeUnit'],
        'GET /api/buildings/{building_id}/units' => ['App\Http\Controllers\BuildingController', 'indexUnits'],
        'PUT /api/units/{id}' => ['App\Http\Controllers\BuildingController', 'updateUnit'],
        'DELETE /api/units/{id}' => ['App\Http\Controllers\BuildingController', 'destroyUnit'],
        'POST /api/buildings/{building_id}/bulk-users' => ['App\Http\Controllers\BuildingController', 'bulkCreateUsers'],

        // Common Areas
        'POST /api/buildings/{building_id}/common-areas' => ['App\Http\Controllers\BuildingController', 'storeCommonArea'],
        'GET /api/buildings/{building_id}/common-areas' => ['App\Http\Controllers\BuildingController', 'indexCommonAreas'],
        'PUT /api/common-areas/{id}' => ['App\Http\Controllers\BuildingController', 'updateCommonArea'],
        'DELETE /api/common-areas/{id}' => ['App\Http\Controllers\BuildingController', 'destroyCommonArea'],

        // Members / Invitations
        'POST /api/buildings/{building_id}/invitations' => ['App\Http\Controllers\BuildingController', 'createInvitation'],
        'GET /api/buildings/{building_id}/invitations' => ['App\Http\Controllers\BuildingController', 'indexInvitations'],
        'GET /api/buildings/{building_id}/members' => ['App\Http\Controllers\BuildingController', 'members'],
        'POST /api/invitations/accept' => ['App\Http\Controllers\BuildingController', 'acceptInvitation'],
        'POST /api/invitations/{id}/resend' => ['App\Http\Controllers\BuildingController', 'resendInvitation'],
        'DELETE /api/invitations/{id}' => ['App\Http\Controllers\BuildingController', 'revokeInvitation'],
        'GET /api/invitations/info' => ['App\Http\Controllers\BuildingController', 'invitationInfo'],

        // Phase 4: Tickets & Notifications
        'GET /api/tickets' => ['App\Http\Controllers\TicketController', 'index'],
        'POST /api/tickets' => ['App\Http\Controllers\TicketController', 'store'],
        'GET /api/tickets/{id}' => ['App\Http\Controllers\TicketController', 'show'],
        'PUT /api/tickets/{id}' => ['App\Http\Controllers\TicketController', 'update'],
        'DELETE /api/tickets/{id}' => ['App\Http\Controllers\TicketController', 'destroy'],
        'PUT /api/tickets/{id}/status' => ['App\Http\Controllers\TicketController', 'updateStatus'],
        'POST /api/tickets/{ticket_id}/comments' => ['App\Http\Controllers\TicketController', 'addComment'],
        'GET /api/tickets/{id}/comments' => ['App\Http\Controllers\TicketController', 'comments'],
        'GET /api/notifications' => ['App\Http\Controllers\NotificationController', 'index'],
        'POST /api/notifications/{id}/read' => ['App\Http\Controllers\NotificationController', 'markAsRead'],
        'POST /api/notifications' => ['App\Http\Controllers\NotificationController', 'store'],

        // Costs & Payments (Phase 3)
        'GET /api/costs' => ['App\Http\Controllers\CostController', 'index'],
        'GET /api/costs/summary' => ['App\Http\Controllers\CostController', 'summary'],
        'POST /api/costs' => ['App\Http\Controllers\CostController', 'store'],
        'POST /api/costs/monthly-charge' => ['App\Http\Controllers\CostController', 'monthlyCharge'],
        'GET /api/costs/charge-preview' => ['App\Http\Controllers\CostController', 'chargePreview'],
        'POST /api/costs/{id}/issue' => ['App\Http\Controllers\CostController', 'issue'],
        'PUT /api/costs/{id}' => ['App\Http\Controllers\CostController', 'update'],
        'DELETE /api/costs/{id}' => ['App\Http\Controllers\CostController', 'destroy'],
        'GET /api/payments' => ['App\Http\Controllers\CostController', 'indexPayments'],
        'GET /api/payments/{payment_id}' => ['App\Http\Controllers\CostController', 'showPayment'],
        'POST /api/payments/submit' => ['App\Http\Controllers\CostController', 'submitPayment'],
        'POST /api/payments/{payment_id}/upload-receipt' => ['App\Http\Controllers\CostController', 'uploadReceipt'],
        'POST /api/payments/{payment_id}/confirm' => ['App\Http\Controllers\CostController', 'confirmPayment'],
        'POST /api/payments/{payment_id}/reject' => ['App\Http\Controllers\CostController', 'rejectPayment'],
        'POST /api/payments/bulk-confirm' => ['App\Http\Controllers\CostController', 'bulkConfirmPayments'],
        'POST /api/payments/bulk-reject' => ['App\Http\Controllers\CostController', 'bulkRejectPayments'],
        'GET /api/buildings/{building_id}/unit-balances' => ['App\Http\Controllers\CostController', 'unitBalances'],
        'GET /api/buildings/{building_id}/ledger' => ['App\Http\Controllers\CostController', 'ledger'],
        'GET /api/buildings/{building_id}/monthly-report' => ['App\Http\Controllers\CostController', 'monthlyReport'],
        'POST /api/buildings/{building_id}/direct-payments' => ['App\Http\Controllers\CostController', 'directPayment'],
        'POST /api/buildings/{building_id}/unit-charges' => ['App\Http\Controllers\CostController', 'unitCharge'],
        'POST /api/buildings/{building_id}/recurring-generate' => ['App\Http\Controllers\CostController', 'recurringGenerate'],
        'GET /api/messages/conversations' => ['App\Http\Controllers\MessageController', 'conversations'],
        'GET /api/messages/thread/{peer_id}' => ['App\Http\Controllers\MessageController', 'thread'],
        'GET /api/messages/unread-count' => ['App\Http\Controllers\MessageController', 'unreadCount'],
        'POST /api/messages' => ['App\Http\Controllers\MessageController', 'store'],
        'GET /api/audit-logs' => ['App\Http\Controllers\AuditController', 'index'],
        'POST /api/penalty-settings' => ['App\Http\Controllers\CostController', 'createPenaltySetting'],
        'GET /api/penalty-settings' => ['App\Http\Controllers\CostController', 'indexPenaltySettings'],
        'PUT /api/penalty-settings/{id}' => ['App\Http\Controllers\CostController', 'updatePenaltySetting'],
        'DELETE /api/penalty-settings/{id}' => ['App\Http\Controllers\CostController', 'destroyPenaltySetting'],

        // Phase 6+: Extra Professional Modules
        'GET /api/bookings' => ['App\Http\Controllers\ExtraModulesController', 'indexBookings'],
        'POST /api/bookings' => ['App\Http\Controllers\ExtraModulesController', 'storeBooking'],
        'GET /api/announcements' => ['App\Http\Controllers\ExtraModulesController', 'indexAnnouncements'],
        'POST /api/announcements' => ['App\Http\Controllers\ExtraModulesController', 'storeAnnouncement'],
        'GET /api/maintenance' => ['App\Http\Controllers\ExtraModulesController', 'indexMaintenance'],
        'POST /api/maintenance' => ['App\Http\Controllers\ExtraModulesController', 'storeMaintenance'],
        'GET /api/votes' => ['App\Http\Controllers\ExtraModulesController', 'indexVotes'],
        'POST /api/votes' => ['App\Http\Controllers\ExtraModulesController', 'storeVote'],
        'GET /api/visitors' => ['App\Http\Controllers\ExtraModulesController', 'indexVisitors'],
        'POST /api/visitors' => ['App\Http\Controllers\ExtraModulesController', 'storeVisitor'],
        'GET /api/documents' => ['App\Http\Controllers\ExtraModulesController', 'indexDocuments'],
        'POST /api/documents' => ['App\Http\Controllers\ExtraModulesController', 'storeDocument'],
        'GET /api/documents/{id}' => ['App\Http\Controllers\ExtraModulesController', 'showDocument'],
        'POST /api/documents/{id}/replace-file' => ['App\Http\Controllers\ExtraModulesController', 'replaceDocumentFile'],
        'GET /api/consumption' => ['App\Http\Controllers\ExtraModulesController', 'indexConsumption'],
        'POST /api/consumption' => ['App\Http\Controllers\ExtraModulesController', 'storeConsumption'],
        'GET /api/emergency-contacts' => ['App\Http\Controllers\ExtraModulesController', 'indexEmergencyContacts'],
        'POST /api/emergency-contacts' => ['App\Http\Controllers\ExtraModulesController', 'storeEmergencyContact'],
        'GET /api/meetings' => ['App\Http\Controllers\ExtraModulesController', 'indexMeetings'],
        'POST /api/meetings' => ['App\Http\Controllers\ExtraModulesController', 'storeMeeting'],
        'GET /api/reviews' => ['App\Http\Controllers\ExtraModulesController', 'indexReviews'],
        // Emergency alerts
        'POST /api/emergency-alerts' => ['App\Http\Controllers\ExtraModulesController', 'storeEmergencyAlert'],
        'GET /api/emergency-alerts' => ['App\Http\Controllers\ExtraModulesController', 'indexEmergencyAlerts'],

        // Meeting minutes
        'POST /api/meetings/{meeting_id}/minutes' => ['App\Http\Controllers\ExtraModulesController', 'storeMeetingMinute'],
        'GET /api/meetings/{meeting_id}/minutes' => ['App\Http\Controllers\ExtraModulesController', 'indexMeetingMinutes'],

        // Review categories
        'POST /api/review-categories' => ['App\Http\Controllers\ExtraModulesController', 'storeReviewCategory'],
        'GET /api/review-categories' => ['App\Http\Controllers\ExtraModulesController', 'indexReviewCategories'],
        'POST /api/reviews' => ['App\Http\Controllers\ExtraModulesController', 'storeReview'],
        // Voting: options, casting, results
        'POST /api/votes/{vote_id}/options' => ['App\Http\Controllers\ExtraModulesController', 'addVoteOptions'],
        'POST /api/votes/{vote_id}/vote' => ['App\Http\Controllers\ExtraModulesController', 'castVote'],
        'GET /api/votes/{vote_id}/results' => ['App\Http\Controllers\ExtraModulesController', 'voteResults'],

        // Status updates (Phase 6+ modules)
        'PUT /api/bookings/{id}/status' => ['App\Http\Controllers\ExtraModulesController', 'updateBookingStatus'],
        'PUT /api/maintenance/{id}/status' => ['App\Http\Controllers\ExtraModulesController', 'updateMaintenanceStatus'],
        'PUT /api/visitors/{id}/checkout' => ['App\Http\Controllers\ExtraModulesController', 'visitorCheckout'],
        'PUT /api/meetings/{id}/status' => ['App\Http\Controllers\ExtraModulesController', 'updateMeetingStatus'],
        'PUT /api/votes/{id}/status' => ['App\Http\Controllers\ExtraModulesController', 'updateVoteStatus'],

        // Editing (Phase 6+ modules) — ویرایش هر چیزی که ثبت شده
        'PUT /api/bookings/{id}' => ['App\Http\Controllers\ExtraModulesController', 'updateBooking'],
        'PUT /api/announcements/{id}' => ['App\Http\Controllers\ExtraModulesController', 'updateAnnouncement'],
        'PUT /api/maintenance/{id}' => ['App\Http\Controllers\ExtraModulesController', 'updateMaintenance'],
        'PUT /api/visitors/{id}' => ['App\Http\Controllers\ExtraModulesController', 'updateVisitor'],
        'PUT /api/documents/{id}' => ['App\Http\Controllers\ExtraModulesController', 'updateDocument'],
        'PUT /api/consumption/{id}' => ['App\Http\Controllers\ExtraModulesController', 'updateConsumption'],
        'PUT /api/emergency-contacts/{id}' => ['App\Http\Controllers\ExtraModulesController', 'updateEmergencyContact'],
        'PUT /api/meetings/{id}' => ['App\Http\Controllers\ExtraModulesController', 'updateMeeting'],
        'PUT /api/reviews/{id}' => ['App\Http\Controllers\ExtraModulesController', 'updateReview'],
        'PUT /api/votes/{id}' => ['App\Http\Controllers\ExtraModulesController', 'updateVote'],

        // Deletion (Phase 6+ modules)
        'DELETE /api/bookings/{id}' => ['App\Http\Controllers\ExtraModulesController', 'destroyBooking'],
        'DELETE /api/announcements/{id}' => ['App\Http\Controllers\ExtraModulesController', 'destroyAnnouncement'],
        'DELETE /api/maintenance/{id}' => ['App\Http\Controllers\ExtraModulesController', 'destroyMaintenance'],
        'DELETE /api/visitors/{id}' => ['App\Http\Controllers\ExtraModulesController', 'destroyVisitor'],
        'DELETE /api/documents/{id}' => ['App\Http\Controllers\ExtraModulesController', 'destroyDocument'],
        'DELETE /api/consumption/{id}' => ['App\Http\Controllers\ExtraModulesController', 'destroyConsumption'],
        'DELETE /api/emergency-contacts/{id}' => ['App\Http\Controllers\ExtraModulesController', 'destroyEmergencyContact'],
        'DELETE /api/meetings/{id}' => ['App\Http\Controllers\ExtraModulesController', 'destroyMeeting'],
        'DELETE /api/reviews/{id}' => ['App\Http\Controllers\ExtraModulesController', 'destroyReview'],
        'DELETE /api/votes/{id}' => ['App\Http\Controllers\ExtraModulesController', 'destroyVote'],
    ];
}
