<?php

use App\Http\Controllers\Api\FingerprintIngestController;
use App\Http\Controllers\Api\TaskProgressController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// API Ingest untuk C# Connector Solution X601 (dibatasi rate limit per token perangkat)
Route::prefix('v1/fingerprint')->middleware('throttle:fingerprint-ingest')->group(function () {
    Route::post('/ingest', [FingerprintIngestController::class, 'ingest'])->name('api.fingerprint.ingest');
    Route::get('/ping', [FingerprintIngestController::class, 'ping'])->name('api.fingerprint.ping');
});

// API Webhook WhatsApp (Meta Cloud API / Gateway)
Route::prefix('v1/whatsapp')->group(function () {
    Route::get('/webhook', [WhatsAppWebhookController::class, 'verify'])->name('api.whatsapp.webhook.verify');
    Route::post('/webhook', [WhatsAppWebhookController::class, 'handle'])->name('api.whatsapp.webhook.handle');
});

// Polling progres proses latar belakang (stateless, tanpa sesi). Dibatasi rate limit.
Route::prefix('v1/progress')->middleware('throttle:api-progress')->group(function () {
    Route::get('/{uuid}', [TaskProgressController::class, 'show'])->name('api.progress.show');
});
