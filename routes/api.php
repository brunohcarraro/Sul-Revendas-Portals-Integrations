<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PortalApiController;
use App\Http\Controllers\VehiclePublishController;

/*
|--------------------------------------------------------------------------
| Sul Revendas - Portal Integration API
|--------------------------------------------------------------------------
|
| Base URL: https://your-domain.com/api
|
| All responses are JSON format.
|
| ENDPOINTS:
|
| GET  /api/health                              - Health check
| GET  /api/portals                             - List available portals
| GET  /api/portals/{portal}/test               - Test portal connection
| GET  /api/portals/{portal}/vehicles           - Get published vehicles
| POST /api/portals/{portal}/vehicles/publish   - Publish a vehicle
| PUT  /api/portals/{portal}/vehicles/{id}      - Update a vehicle
| DELETE /api/portals/{portal}/vehicles/{id}    - Remove a vehicle
| PATCH /api/portals/{portal}/vehicles/{id}/status - Update vehicle status
| GET  /api/portals/{portal}/leads              - Get leads from portal
| POST /api/portals/publish-all                 - Publish to multiple portals
|
*/

// Health check
Route::get('/health', [PortalApiController::class, 'health']);

// List available portals
Route::get('/portals', [PortalApiController::class, 'listPortals']);

// Publish to multiple portals at once
Route::post('/portals/publish-all', [PortalApiController::class, 'publishToAll']);

// Test connection to a portal
Route::get('/portals/{portal}/test', [PortalApiController::class, 'testConnection']);

// Vehicles endpoints per portal
Route::prefix('/portals/{portal}')->group(function () {
    // Get published vehicles
    Route::get('/vehicles', [PortalApiController::class, 'getPublishedVehicles']);

    // Publish a vehicle
    Route::post('/vehicles/publish', [PortalApiController::class, 'publishVehicle']);

    // Update a vehicle
    Route::put('/vehicles/{externalId}', [PortalApiController::class, 'updateVehicle']);

    // Remove a vehicle
    Route::delete('/vehicles/{externalId}', [PortalApiController::class, 'removeVehicle']);

    // Update vehicle status
    Route::patch('/vehicles/{externalId}/status', [PortalApiController::class, 'updateVehicleStatus']);

    // Get leads
    Route::get('/leads', [PortalApiController::class, 'getLeads']);
});

// Legacy endpoint (backwards compatibility)
Route::post('/vehicles/publish', [VehiclePublishController::class, 'publish']);
