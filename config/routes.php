<?php

declare(strict_types=1);

namespace App\Config;

final class Routes
{
    public static array $routes = [
        // Auth
        'POST /api/auth/register' => ['App\Http\Controllers\AuthController', 'register'],
        'POST /api/auth/login' => ['App\Http\Controllers\AuthController', 'login'],
        'POST /api/auth/refresh' => ['App\Http\Controllers\AuthController', 'refresh'],
        'POST /api/auth/logout' => ['App\Http\Controllers\AuthController', 'logout'],

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

        // Floors
        'POST /api/buildings/{building_id}/floors' => ['App\Http\Controllers\BuildingController', 'storeFloor'],
        'GET /api/buildings/{building_id}/floors' => ['App\Http\Controllers\BuildingController', 'indexFloors'],

        // Units
        'POST /api/buildings/{building_id}/units' => ['App\Http\Controllers\BuildingController', 'storeUnit'],
        'GET /api/buildings/{building_id}/units' => ['App\Http\Controllers\BuildingController', 'indexUnits'],

        // Common Areas
        'POST /api/buildings/{building_id}/common-areas' => ['App\Http\Controllers\BuildingController', 'storeCommonArea'],
        'GET /api/buildings/{building_id}/common-areas' => ['App\Http\Controllers\BuildingController', 'indexCommonAreas'],

        // Members / Invitations
        'POST /api/buildings/{building_id}/invitations' => ['App\Http\Controllers\BuildingController', 'createInvitation'],
        'GET /api/buildings/{building_id}/members' => ['App\Http\Controllers\BuildingController', 'members'],
        'POST /api/invitations/accept' => ['App\Http\Controllers\BuildingController', 'acceptInvitation'],

        // Phase 4: Tickets & Notifications
        'GET /api/tickets' => ['App\Http\Controllers\TicketController', 'index'],
        'POST /api/tickets' => ['App\Http\Controllers\TicketController', 'store'],
        'GET /api/tickets/{id}' => ['App\Http\Controllers\TicketController', 'show'],
        'PUT /api/tickets/{id}/status' => ['App\Http\Controllers\TicketController', 'updateStatus'],
        'POST /api/tickets/{ticket_id}/comments' => ['App\Http\Controllers\TicketController', 'addComment'],
        'GET /api/notifications' => ['App\Http\Controllers\NotificationController', 'index'],
        'POST /api/notifications/{id}/read' => ['App\Http\Controllers\NotificationController', 'markAsRead'],
        'POST /api/notifications' => ['App\Http\Controllers\NotificationController', 'store'],

        // Costs & Payments (Phase 3)
        'POST /api/costs' => ['App\Http\Controllers\CostController', 'store'],
        'POST /api/payments/submit' => ['App\Http\Controllers\CostController', 'submitPayment'],
        'POST /api/payments/{payment_id}/upload-receipt' => ['App\Http\Controllers\CostController', 'uploadReceipt'],
        'POST /api/payments/{payment_id}/confirm' => ['App\Http\Controllers\CostController', 'confirmPayment'],
        'POST /api/penalty-settings' => ['App\Http\Controllers\CostController', 'createPenaltySetting'],

        // Phase 6+: Extra Professional Modules
        'POST /api/bookings' => ['App\Http\Controllers\ExtraModulesController', 'storeBooking'],
        'POST /api/announcements' => ['App\Http\Controllers\ExtraModulesController', 'storeAnnouncement'],
        'POST /api/maintenance' => ['App\Http\Controllers\ExtraModulesController', 'storeMaintenance'],
        'POST /api/votes' => ['App\Http\Controllers\ExtraModulesController', 'storeVote'],
        'POST /api/visitors' => ['App\Http\Controllers\ExtraModulesController', 'storeVisitor'],
        'POST /api/documents' => ['App\Http\Controllers\ExtraModulesController', 'storeDocument'],
        'POST /api/consumption' => ['App\Http\Controllers\ExtraModulesController', 'storeConsumption'],
        'POST /api/emergency-contacts' => ['App\Http\Controllers\ExtraModulesController', 'storeEmergencyContact'],
        'POST /api/meetings' => ['App\Http\Controllers\ExtraModulesController', 'storeMeeting'],
        'POST /api/reviews' => ['App\Http\Controllers\ExtraModulesController', 'storeReview'],
    ];
}
