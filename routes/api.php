<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\AdminPresenceController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminLeaveController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AdminTrackLocationController;
use App\Http\Controllers\Api\MediaController;
use Illuminate\Support\Facades\Route;

// Media endpoint: serve storage files through Laravel so CORS headers are applied.
// Path boleh multi-segment, contoh: /media/images/Online/Payment/xxxx.jpg
Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.show');

Route::get('/app-version', function (\Illuminate\Http\Request $request) {
    $appName = $request->query('app_name', 'presence');
    
    $latestVersion = \Illuminate\Support\Facades\DB::table('app_versions')
        ->where('app_name', $appName)
        ->where('is_active', true)
        ->orderBy('build_number', 'desc')
        ->first();
        
    if (!$latestVersion) {
        return response()->json([
            'latest_version' => '1.0.0',
            'version_code' => 1,
            'force_update' => false,
            'download_url' => '',
            'release_notes' => 'No active version found for this app.'
        ]);
    }
    
    // Construct the download URL via /media/{path} (MediaController) agar response
    // selalu membawa header CORS. Hindari url('storage/...') yang diserve langsung
    // oleh web server tanpa header CORS.
    $downloadUrl = $latestVersion->apk_file
        ? route('media.show', ['path' => $latestVersion->apk_file])
        : '';
        
    return response()->json([
        'latest_version' => $latestVersion->version_code,
        'version_code' => (int) $latestVersion->build_number,
        'force_update' => (bool) $latestVersion->is_force_update,
        'download_url' => $downloadUrl,
        'release_notes' => $latestVersion->release_notes ?? 'Pembaruan aplikasi untuk performa yang lebih baik dan perbaikan bug.'
    ]);
});

Route::post('/login', [AuthController::class, 'login']);
Route::get('/login', function () {
    return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
})->name('login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user-presence', [PresenceController::class, 'getUserPresence']);
    Route::post('/check-in', [PresenceController::class, 'checkIn']);
    Route::post('/check-out', [PresenceController::class, 'checkOut']);
    Route::get('/stores', [PresenceController::class, 'getStores']);
    Route::get('/shift-stores', [PresenceController::class, 'getShiftStores']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Employee location tracking (mobile ingestion)
    Route::post('/location', [LocationController::class, 'store']);
    Route::post('/device-tokens', [LocationController::class, 'registerToken']);
    Route::delete('/device-tokens', [LocationController::class, 'deregisterToken']);

    // Sales Order Delivery Routes
    Route::get('/sales-orders/search', [\App\Http\Controllers\Api\SalesOrderController::class, 'search']);
    Route::post('/sales-orders/ready-to-ship', [\App\Http\Controllers\Api\SalesOrderController::class, 'markReadyToShip']);
    Route::post('/sales-orders/delivery-update', [\App\Http\Controllers\Api\SalesOrderController::class, 'updateDelivery']);
    Route::post('/sales-orders/payment-proofs/printed', [\App\Http\Controllers\Api\SalesOrderController::class, 'markPaymentProofsPrinted']);

    Route::prefix('leaves')->group(function () {
        Route::get('/', [LeaveController::class, 'index']);
        Route::post('/', [LeaveController::class, 'store']);
        Route::get('/{id}', [LeaveController::class, 'show']);
        Route::put('/{id}', [LeaveController::class, 'update']);
        Route::delete('/{id}', [LeaveController::class, 'destroy']);
    });

    Route::prefix('salaries')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\SalaryController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\SalaryController::class, 'show']);
    });

    Route::prefix('procurement')->group(function () {
        Route::get('/products', [\App\Http\Controllers\Api\ProcurementController::class, 'products']);
        Route::get('/requests', [\App\Http\Controllers\Api\ProcurementController::class, 'index']);
        Route::post('/requests', [\App\Http\Controllers\Api\ProcurementController::class, 'store']);
        Route::get('/requests/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'show']);
        Route::post('/requests/items/{id}/approve', [\App\Http\Controllers\Api\ProcurementController::class, 'approveItem']);
        Route::post('/requests/items/{id}/reject', [\App\Http\Controllers\Api\ProcurementController::class, 'rejectItem']);
        Route::post('/requests/items/{id}/cancel', [\App\Http\Controllers\Api\ProcurementController::class, 'cancelItem']);
        Route::post('/requests/{id}/create-invoice', [\App\Http\Controllers\Api\ProcurementController::class, 'createInvoice']);
    });

    Route::prefix('storage-stocks')->group(function () {
        Route::get('/products', [\App\Http\Controllers\Api\StorageStockController::class, 'products']);
        Route::get('/today-status', [\App\Http\Controllers\Api\StorageStockController::class, 'todayStatus']);
        Route::get('/', [\App\Http\Controllers\Api\StorageStockController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\StorageStockController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\StorageStockController::class, 'show']);
    });

    Route::prefix('readiness')->group(function () {
        Route::get('/status', [\App\Http\Controllers\Api\ReadinessController::class, 'checkStatus']);
        Route::post('/', [\App\Http\Controllers\Api\ReadinessController::class, 'store']);
    });

    Route::prefix('closing-stores')->group(function () {
        Route::get('/active-draft', [\App\Http\Controllers\Api\ClosingStoreController::class, 'activeDraft']);
        Route::get('/unpaid-transactions', [\App\Http\Controllers\Api\ClosingStoreController::class, 'unpaidTransactions']);
        Route::post('/save', [\App\Http\Controllers\Api\ClosingStoreController::class, 'save']);
        Route::get('/fuel-services', [\App\Http\Controllers\Api\ClosingStoreController::class, 'indexFuelServices']);
        Route::post('/fuel-services', [\App\Http\Controllers\Api\ClosingStoreController::class, 'createFuelService']);
        Route::get('/vehicles', [\App\Http\Controllers\Api\ClosingStoreController::class, 'vehicles']);
        Route::get('/suppliers', [\App\Http\Controllers\Api\ClosingStoreController::class, 'suppliers']);
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
        Route::get('leaves/{id}', [AdminLeaveController::class, 'show']);
        Route::post('leaves/{id}/approve', [AdminLeaveController::class, 'approve']);
        Route::post('leaves/{id}/reject', [AdminLeaveController::class, 'reject']);
        Route::post('leaves/export', [AdminLeaveController::class, 'export']);

        // Employee location tracking (admin)
        // Bungkus rute sensitif dengan middleware 'admin' untuk memastikan
        // hanya user ber-peran admin yang bisa memicu pelacakan lokasi.
        Route::middleware('admin')->group(function () {
            Route::post('track-location/{user}', [AdminTrackLocationController::class, 'trigger']);
            Route::get('track-location/{location_request}', [AdminTrackLocationController::class, 'showRequest']);
            Route::get('employee-locations', [AdminTrackLocationController::class, 'latestLocations']);
            Route::get('employee-locations/{user}', [AdminTrackLocationController::class, 'history']);
        });
     });
});
