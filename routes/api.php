<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    AuthController,
    ReservationController,
    RoomController,
    UserController,
    BranchController,
    GuestController,
    SecurityBlacklistController 
};

/*
|--------------------------------------------------------------------------
| المسارات العامة (Public)
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| المسارات المحمية (Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // 1. فحص الحالة الشخصية (User Profile & State)
    Route::get('/user-status', function (Request $request) {
        return response()->json([
            'status'      => 'success',
            'user'        => [
                'name'    => $request->user()->name,
                'roles'   => $request->user()->getRoleNames(),
                'branch'  => $request->user()->branch_id,
                'status'  => $request->user()->status
            ]
        ]);
    });

    // 2. منظومة الأمن (Security & Blacklist)
    Route::prefix('security')->group(function () {
        Route::middleware('role:hq_admin|hq_security|hq_auditor,api')->group(function () {
            Route::get('/blacklist', [SecurityBlacklistController::class, 'index']);
            Route::get('/notifications', [SecurityBlacklistController::class, 'getNotifications']);
            Route::get('/notifications/unread-count', [SecurityBlacklistController::class, 'unreadCount']);
            Route::patch('/notifications/{id}/read', [SecurityBlacklistController::class, 'markAsRead']);
        });

        Route::middleware('role:hq_admin|hq_security,api')->group(function () {
            Route::post('/blacklist/add', [SecurityBlacklistController::class, 'store']);
        });
    });

    // 3. محرك الحجوزات المطور (Advanced Reservations Engine)
    Route::prefix('reservations')->group(function () {
        
        // أ) مسار التدقيق والقفل (Audit & Lock) - الأهم في الوثيقة
        Route::post('/{id}/audit', [ReservationController::class, 'audit'])
             ->middleware('role:hq_admin|hq_supervisor|hq_security|hq_auditor,api');
        
        // ب) تسجيل الخروج (Check-out)
        Route::post('/{reservation}/checkout', [ReservationController::class, 'checkOut']);

        // ج) إدارة القفل اليدوي (Toggle Lock)
        Route::patch('/{id}/toggle-lock', [ReservationController::class, 'toggleLock'])
             ->middleware('role:hq_admin|hq_security,api');

        // د) عرض الوثائق الأمنية
        Route::get('/{reservation}/documents', [ReservationController::class, 'viewDocuments'])
             ->middleware('role:branch_reception|hq_admin|hq_security|hq_auditor,api');
    });

    // مسارات الـ CRUD للحجوزات (تستخدم index, store, update, show)
    Route::apiResource('reservations', ReservationController::class);

    // 4. إدارة النزلاء (Guests)
    Route::prefix('guests')->group(function () {
        Route::post('/search', [GuestController::class, 'search']);
        // مسار خاص للتحقق من الهاشات (Fingerprint Check)
        Route::post('/verify-hashes', [GuestController::class, 'verifyHashes'])
             ->middleware('role:hq_security|hq_admin,api');
    });
    Route::apiResource('guests', GuestController::class);

    // 5. إدارة الغرف (Rooms)
    Route::prefix('rooms')->group(function () {
        Route::get('/', [RoomController::class, 'index']);
        Route::patch('/{room}/status', [RoomController::class, 'updateStatus'])
             ->middleware('role:hq_admin|hq_supervisor|branch_reception,api');
    });

    // 6. الإدارة المركزية (System Admin)
    Route::middleware('role:hq_admin,api')->group(function () {
        Route::apiResource('branches', BranchController::class);
        Route::apiResource('users', UserController::class);
        Route::get('/audit-logs', [UserController::class, 'systemLogs']);
    });

    Route::post('/logout', [AuthController::class, 'logout']);
});