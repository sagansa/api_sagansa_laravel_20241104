<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\AdminDashboardController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user-presence', [PresenceController::class, 'getUserPresence']);
    Route::post('/check-in', [PresenceController::class, 'checkIn']);
    Route::post('/check-out', [PresenceController::class, 'checkOut']);
    Route::post('/presence/{id}/retry-face', [PresenceController::class, 'retryFaceVerification']);
    Route::get('/stores', [PresenceController::class, 'getStores']);
    Route::get('/shift-stores', [PresenceController::class, 'getShiftStores']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('leaves')->group(function () {
        Route::get('/', [LeaveController::class, 'index']);
        Route::post('/', [LeaveController::class, 'store']);
        Route::get('/{id}', [LeaveController::class, 'show']);
        Route::put('/{id}', [LeaveController::class, 'update']);
        Route::delete('/{id}', [LeaveController::class, 'destroy']);
    });
<<<<<<< HEAD

    // Face Recognition routes
    Route::prefix('face')->group(function () {
        Route::post('/register', [FaceRecognitionController::class, 'register']);
        Route::post('/verify', [FaceRecognitionController::class, 'verify']);
        Route::get('/status', [FaceRecognitionController::class, 'status']);
        Route::delete('/remove', [FaceRecognitionController::class, 'remove']);
        Route::get('/service-health', [FaceRecognitionController::class, 'serviceHealth']);
    });

    // Admin Dashboard routes
    Route::prefix('admin')->group(function () {
        Route::get('/face-recognition-stats', [AdminDashboardController::class, 'getFaceRecognitionStats']);
        Route::get('/users-with-face-issues', [AdminDashboardController::class, 'getUsersWithFaceIssues']);
        Route::get('/security-flags-summary', [AdminDashboardController::class, 'getSecurityFlagsSummary']);
        Route::get('/face-registration-stats', [AdminDashboardController::class, 'getFaceRegistrationStats']);
        Route::get('/face-accuracy-metrics', [AdminDashboardController::class, 'getFaceAccuracyMetrics']);
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
    Route::post('/persistent/presence/{id}/retry-face', [PresenceController::class, 'retryFaceVerification']);
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

    // Admin Dashboard routes (persistent)
    Route::prefix('persistent/admin')->group(function () {
        Route::get('/face-recognition-stats', [AdminDashboardController::class, 'getFaceRecognitionStats']);
        Route::get('/users-with-face-issues', [AdminDashboardController::class, 'getUsersWithFaceIssues']);
        Route::get('/security-flags-summary', [AdminDashboardController::class, 'getSecurityFlagsSummary']);
        Route::get('/face-registration-stats', [AdminDashboardController::class, 'getFaceRegistrationStats']);
        Route::get('/face-accuracy-metrics', [AdminDashboardController::class, 'getFaceAccuracyMetrics']);
    });
=======
>>>>>>> parent of 32b6b30 (update face recognition)
});
