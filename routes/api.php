<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FaceRecognitionController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\PresenceController;
use Illuminate\Support\Facades\Route;

// Authentication routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/validate-token', [AuthController::class, 'validateToken']);

// Routes protected by Sanctum (backward compatibility)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/revoke-all-tokens', [AuthController::class, 'revokeAllTokens']);
    
    Route::get('/user-presence', [PresenceController::class, 'getUserPresence']);
    Route::post('/check-in', [PresenceController::class, 'checkIn']);
    Route::post('/check-out', [PresenceController::class, 'checkOut']);
    Route::get('/stores', [PresenceController::class, 'getStores']);
    Route::get('/shift-stores', [PresenceController::class, 'getShiftStores']);

    Route::prefix('leaves')->group(function () {
        Route::get('/', [LeaveController::class, 'index']);
        Route::post('/', [LeaveController::class, 'store']);
        Route::get('/{id}', [LeaveController::class, 'show']);
        Route::put('/{id}', [LeaveController::class, 'update']);
        Route::delete('/{id}', [LeaveController::class, 'destroy']);
    });

    // Face Recognition routes
    Route::prefix('face')->group(function () {
        Route::post('/register', [FaceRecognitionController::class, 'register']);
        Route::post('/verify', [FaceRecognitionController::class, 'verify']);
        Route::get('/status', [FaceRecognitionController::class, 'status']);
        Route::delete('/remove', [FaceRecognitionController::class, 'remove']);
        Route::get('/service-health', [FaceRecognitionController::class, 'serviceHealth']);
    });
});

// Routes protected by Sanctum (persistent tokens)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/persistent/me', [AuthController::class, 'me']);
    Route::post('/persistent/logout', [AuthController::class, 'logout']);
    Route::post('/persistent/revoke-all-tokens', [AuthController::class, 'revokeAllTokens']);
    
    Route::get('/persistent/user-presence', [PresenceController::class, 'getUserPresence']);
    Route::post('/persistent/check-in', [PresenceController::class, 'checkIn']);
    Route::post('/persistent/check-out', [PresenceController::class, 'checkOut']);
    Route::get('/persistent/stores', [PresenceController::class, 'getStores']);
    Route::get('/persistent/shift-stores', [PresenceController::class, 'getShiftStores']);

    Route::prefix('persistent/leaves')->group(function () {
        Route::get('/', [LeaveController::class, 'index']);
        Route::post('/', [LeaveController::class, 'store']);
        Route::get('/{id}', [LeaveController::class, 'show']);
        Route::put('/{id}', [LeaveController::class, 'update']);
        Route::delete('/{id}', [LeaveController::class, 'destroy']);
    });

    // Face Recognition routes (persistent)
    Route::prefix('persistent/face')->group(function () {
        Route::post('/register', [FaceRecognitionController::class, 'register']);
        Route::post('/verify', [FaceRecognitionController::class, 'verify']);
        Route::get('/status', [FaceRecognitionController::class, 'status']);
        Route::delete('/remove', [FaceRecognitionController::class, 'remove']);
        Route::get('/service-health', [FaceRecognitionController::class, 'serviceHealth']);
    });
});
