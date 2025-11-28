<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ArrivalManageController;
use App\Http\Controllers\ArrivalCheckController;
use App\Http\Controllers\ItemScanController;
use App\Http\Controllers\CheckSheetController;
use App\Http\Controllers\LevelStockController;
use App\Http\Controllers\ArrivalScheduleController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SupplierContactController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes (no authentication required)
Route::prefix('public')->group(function () {
    // Health check
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now(),
            'service' => 'AMS API'
        ]);
    });
    
    // Public Dashboard routes (for display screens)
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/dn-details', [DashboardController::class, 'getDnDetails']);
        Route::get('/schedule', [DashboardController::class, 'getScheduleData']);
    });
    
    // Public Arrival Check routes (for driver interface)
    Route::prefix('arrival-check')->group(function () {
        Route::get('/', [ArrivalCheckController::class, 'index']);
        Route::post('/checkin', [ArrivalCheckController::class, 'checkin']);
        Route::post('/checkout', [ArrivalCheckController::class, 'checkout']);
    });
    
    // Debug token validation
    Route::post('/debug-token', function (Request $request) {
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'No token provided'
            ]);
        }
        
        $authService = app(\App\Services\AuthService::class);
        $userData = $authService->validateToken($token);
        
        return response()->json([
            'success' => $userData !== null,
            'user_data' => $userData,
            'token_preview' => substr($token, 0, 20) . '...',
            'be_sphere_url' => config('app.be_sphere_url')
        ]);
    });
    
    // Debug table structure
    Route::get('/debug-table/{table}', function ($table) {
        try {
            $columns = \Schema::getColumnListing($table);
            return response()->json([
                'success' => true,
                'table' => $table,
                'columns' => $columns
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    });
});

// Protected routes (JWT authentication required)
Route::middleware(['jwt.auth'])->group(function () {
    
    // Dashboard routes
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/dn-details', [DashboardController::class, 'getDnDetails']);
        Route::get('/schedule', [DashboardController::class, 'getScheduleData']);
    });

    // Arrival Management routes (Admin Warehouse, Superadmin)
    Route::prefix('arrival-manage')->middleware(['role:admin,superadmin'])->group(function () {
        Route::get('/', [ArrivalManageController::class, 'index']);
        Route::post('/', [ArrivalManageController::class, 'store']);
        Route::put('/{id}', [ArrivalManageController::class, 'update']);
        Route::delete('/{id}', [ArrivalManageController::class, 'destroy']);
        Route::get('/suppliers', [ArrivalManageController::class, 'getSuppliers']);
        Route::get('/available-arrivals', [ArrivalManageController::class, 'getAvailableArrivals']);
        Route::get('/arrival-transactions', [ArrivalManageController::class, 'getArrivalTransactions']);
        Route::get('/statistics', [ArrivalManageController::class, 'getStatistics']);
    });

    // Arrival Check routes (Operator Warehouse, Superadmin)
    Route::prefix('arrival-check')->middleware(['role:operator,superadmin'])->group(function () {
        Route::get('/', [ArrivalCheckController::class, 'index']);
        Route::post('/checkin', [ArrivalCheckController::class, 'checkin']);
        Route::post('/checkout', [ArrivalCheckController::class, 'checkout']);
        Route::post('/sync-visitor', [ArrivalCheckController::class, 'syncVisitorData']);
        Route::get('/statistics', [ArrivalCheckController::class, 'getStatistics']);
    });

    // Item Scan routes (Operator Warehouse, Superadmin)
    Route::prefix('item-scan')->middleware(['role:operator,superadmin'])->group(function () {
        Route::get('/', [ItemScanController::class, 'index']);
        Route::post('/start-session', [ItemScanController::class, 'startSession']);
        Route::post('/scan-dn', [ItemScanController::class, 'scanDn']);
        Route::get('/dn-items', [ItemScanController::class, 'getDnItemsList']);
        Route::post('/scan-item', [ItemScanController::class, 'scanItem']);
        Route::post('/complete-session', [ItemScanController::class, 'completeSession']);
        Route::post('/mark-incomplete', [ItemScanController::class, 'markIncompleteQty']);
        Route::get('/session/{sessionId}', [ItemScanController::class, 'getSessionDetails']);
        Route::get('/statistics', [ItemScanController::class, 'getStatistics']);
    });

    // Check Sheet routes (Operator Warehouse, Superadmin)
    Route::prefix('check-sheet')->middleware(['role:operator,superadmin'])->group(function () {
        Route::get('/', [CheckSheetController::class, 'index']);
        Route::post('/submit', [CheckSheetController::class, 'submit']);
        Route::get('/details', [CheckSheetController::class, 'getDetails']);
        Route::get('/statistics', [CheckSheetController::class, 'getStatistics']);
        Route::get('/history', [CheckSheetController::class, 'getHistory']);
        Route::get('/download-pdf', [CheckSheetController::class, 'downloadPdf']);
    });

    // Level Stock routes (Admin Warehouse, Superadmin)
    Route::prefix('level-stock')->middleware(['role:admin,superadmin'])->group(function () {
        Route::get('/', [LevelStockController::class, 'index']);
        Route::get('/summary', [LevelStockController::class, 'getSummary']);
        Route::get('/warehouses', [LevelStockController::class, 'getWarehouses']);
        Route::get('/low-stock-alerts', [LevelStockController::class, 'getLowStockAlerts']);
        Route::get('/export', [LevelStockController::class, 'export']);
    });

    // Supplier contacts (all authenticated roles)
    Route::get('/supplier-contacts', [SupplierContactController::class, 'index']);

    // Arrival Schedule routes (Admin Warehouse, Operator Warehouse, Superadmin)
    Route::prefix('arrival-schedule')->middleware(['role:admin,operator,superadmin'])->group(function () {
        Route::get('/', [ArrivalScheduleController::class, 'index']);
        Route::get('/dn-details', [ArrivalScheduleController::class, 'getDnDetails']);
        Route::get('/performance', [ArrivalScheduleController::class, 'getPerformance']);
    });

    // Sync routes (Superadmin only)
    Route::prefix('sync')->middleware(['role:superadmin'])->group(function () {
        Route::post('/arrivals', [SyncController::class, 'syncArrivalTransactions']);
        Route::post('/partners', [SyncController::class, 'syncBusinessPartners']);
        Route::post('/manual', [SyncController::class, 'manualSync']);
        Route::post('/visitor-checkout', [SyncController::class, 'syncVisitorCheckout']);
        Route::get('/statistics', [SyncController::class, 'getStatistics']);
        Route::get('/logs', [SyncController::class, 'getLogs']);
        Route::get('/last-sync', [SyncController::class, 'getLastSync']);
    });

    // User info route (all authenticated users)
    Route::get('/user', function (Request $request) {
        $user = $request->get('auth_user');

        $role = $user->role ? [
            'id' => $user->role->id,
            'name' => $user->role->name,
            'slug' => $user->role->slug,
            'level' => $user->role->level ?? 0,
        ] : null;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $role,
                    'department' => $user->department ? [
                        'id' => $user->department->id,
                        'name' => $user->department->name,
                        'code' => $user->department->code,
                    ] : null,
                ],
                'permissions' => getUserPermissions($user),
            ]
        ]);
    });
});

// Helper function to get user permissions based on role and department
function getUserPermissions($user) {
    $permissions = [
        'dashboard' => false,
        'arrival_manage' => false,
        'arrival_check' => false,
        'item_scan' => false,
        'check_sheet' => false,
        'level_stock' => false,
        'arrival_schedule' => false,
        'sync' => false,
    ];
    
    if (!$user->role) {
        return $permissions;
    }
    
    $roleSlug = $user->role->slug;
    $departmentCode = $user->department ? $user->department->code : null;
    $isWarehouse = $departmentCode === 'WH';
    
    switch ($roleSlug) {
        case 'superadmin':
            // Superadmin has full access
            $permissions = [
                'dashboard' => true,
                'arrival_manage' => true,
                'arrival_check' => true,
                'item_scan' => true,
                'check_sheet' => true,
                'level_stock' => true,
                'arrival_schedule' => true,
                'sync' => true,
            ];
            break;
            
        case 'admin':
            // Admin with Warehouse department
            if ($isWarehouse) {
                $permissions = [
                    'dashboard' => true,
                    'arrival_manage' => true,
                    'arrival_check' => false,
                    'item_scan' => false,
                    'check_sheet' => false,
                    'level_stock' => true,
                    'arrival_schedule' => true,
                    'sync' => false,
                ];
            }
            break;
            
        case 'operator':
            // Operator with Warehouse department
            if ($isWarehouse) {
                $permissions = [
                    'dashboard' => true,
                    'arrival_manage' => false,
                    'arrival_check' => true,
                    'item_scan' => true,
                    'check_sheet' => true,
                    'level_stock' => false,
                    'arrival_schedule' => true,
                    'sync' => false,
                ];
            }
            break;
    }
    
    return $permissions;
}
