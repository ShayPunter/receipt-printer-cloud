<?php

use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\ApiKeyAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
| To enable API key authentication, set API_KEY in your .env file.
| Clients should send the key via X-API-Key header or api_key query param.
|
*/

// Apply API key authentication to all routes
Route::middleware([ApiKeyAuth::class])->group(function () {

// Webhook endpoints
Route::prefix('webhook')->group(function () {
    // Store webhook data (queued for processing)
    Route::post('/', [WebhookController::class, 'store']);

    // Process a specific message by ID
    Route::post('/process/{id}', [WebhookController::class, 'process']);

    // Store and process immediately in one call
    Route::post('/process-immediately', [WebhookController::class, 'storeAndProcess']);
});

// Sync endpoints
Route::prefix('sync')->group(function () {
    // Get unsynced action items
    Route::get('/', [SyncController::class, 'index']);

    // Mark action items as synced
    Route::post('/mark-synced', [SyncController::class, 'markSynced']);

    // Get sync statistics
    Route::get('/stats', [SyncController::class, 'stats']);
});

}); // End of API key auth middleware group
