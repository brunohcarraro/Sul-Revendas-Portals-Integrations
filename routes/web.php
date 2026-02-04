<?php

use App\Http\Controllers\OAuthCallbackController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\VehiclePublishController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// OAuth Authorization Routes
Route::prefix('oauth')->name('oauth.')->group(function () {
    // OLX
    Route::get('/olx/authorize', [OAuthCallbackController::class, 'olxAuthorize'])
        ->name('olx.authorize');
    Route::get('/olx/callback', [OAuthCallbackController::class, 'olxCallback'])
        ->name('olx.callback');

    // Mercado Livre
    Route::get('/mercadolivre/authorize', [OAuthCallbackController::class, 'mercadoLivreAuthorize'])
        ->name('mercadolivre.authorize');
    Route::get('/mercadolivre/callback', [OAuthCallbackController::class, 'mercadoLivreCallback'])
        ->name('mercadolivre.callback');
});

// Webhook Routes for Lead Capture
Route::prefix('webhooks')->name('webhooks.')->group(function () {
    // OLX Lead Webhook
    Route::post('/olx/lead', [WebhookController::class, 'olxLead'])
        ->name('olx.lead');

    // WebMotors Lead Webhook
    Route::post('/webmotors/lead', [WebhookController::class, 'webmotorsLead'])
        ->name('webmotors.lead');

    // Mercado Livre Notifications Webhook
    Route::post('/mercadolivre/notifications', [WebhookController::class, 'mercadoLivreNotification'])
        ->name('mercadolivre.notifications');

    // iCarros Lead Webhook
    Route::post('/icarros/lead', [WebhookController::class, 'icarrosLead'])
        ->name('icarros.lead');
});

Route::post('/vehicles/publish', [VehiclePublishController::class, 'publish']);