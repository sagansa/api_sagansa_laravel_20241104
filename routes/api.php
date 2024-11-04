<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\api\LeaveController;
use App\Http\Controllers\api\PresenceController;
use App\Http\Controllers\api\SalaryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user-presence', [PresenceController::class, 'getUserPresence']);
    Route::post('/check-in', [PresenceController::class, 'checkIn']);
    Route::post('/check-out', [PresenceController::class, 'checkOut']);
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

    // Route untuk salary
    Route::prefix('salary')->group(function () {
        // Get salary calculation
        Route::get('/calculate', [SalaryController::class, 'getMySalary']);

        // Get current rate
        Route::get('/rate', [SalaryController::class, 'getCurrentRate']);

        // Get monthly salaries list
        Route::get('/monthly', [SalaryController::class, 'getMonthlySalaries']);
    });
});
