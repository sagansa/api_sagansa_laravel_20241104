<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\AdminPresenceController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminLeaveController;
use App\Http\Controllers\Api\AdminReportController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user-presence', [PresenceController::class, 'getUserPresence']);
    Route::post('/check-in', [PresenceController::class, 'checkIn']);
    Route::post('/check-out', [PresenceController::class, 'checkOut']);
    Route::get('/stores', [PresenceController::class, 'getStores']);
    Route::get('/shift-stores', [AdminReportController::class, 'getShiftStores']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('leaves')->group(function () {
        Route::get('/', [LeaveController::class, 'index']);
        Route::post('/', [LeaveController::class, 'store']);
        Route::get('/{id}', [LeaveController::class, 'show']);
        Route::put('/{id}', [LeaveController::class, 'update']);
        Route::delete('/{id}', [LeaveController::class, 'destroy']);
    });

    Route::prefix('admin')->group(function () {

        // Dashboard routes
        Route::get('dashboard-stats', [AdminDashboardController::class, 'stats']);
        Route::get('presence-trends', [AdminDashboardController::class, 'trends']);
        Route::get('absent-employees', [AdminDashboardController::class, 'absentEmployees']);
        Route::get('not-checked-out', [AdminDashboardController::class, 'notCheckedOut']);
        Route::get('recent-activities', [AdminReportController::class, 'recentActivities']);

        // Reports routes
        Route::get('reports/types', [AdminReportController::class, 'types']);
        Route::get('reports/monthly', [AdminReportController::class, 'monthly']);
        Route::get('late-employees', [AdminReportController::class, 'lateEmployees']);
        Route::get('attendance-summary', [AdminReportController::class, 'attendanceSummary']);
        Route::post('reports/export', [AdminReportController::class, 'export']);

        // Presence management routes
        Route::apiResource('presences', AdminPresenceController::class);
        Route::post('presences/check-duplicate', [AdminPresenceController::class, 'checkDuplicate']);

        // Export routes
        Route::post('presences/export', [AdminPresenceController::class, 'export']);
        Route::get('presences/export/{jobId}/status', [AdminReportController::class, 'getExportStatus']);
        Route::get('presences/export/history', [AdminReportController::class, 'getExportHistory']);
        Route::delete('presences/export/{jobId}', [AdminPresenceController::class, 'cancelExport']);

        // Leave management routes
        Route::get('leaves', [AdminLeaveController::class, 'index']);
     });
});
