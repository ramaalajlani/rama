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
Route::post('/refresh-token', [AuthController::class, 'refresh']);

/*
|--------------------------------------------------------------------------
| المسارات المحمية (Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // 1. فحص الحالة الشخصية
    Route::get('/user-status', function (Request $request) {
        return response()->json([
            'status'      => 'success',
            'user_name'   => $request->user()->full_name, // تأكدي من استخدام full_name
            'active_role' => $request->user()->getRoleNames(),
            'branch_id'   => $request->user()->branch_id,
        ]);
    });

    // 2. إدارة النزلاء (Guest Intelligence)
    Route::prefix('guests')->group(function () {
        // البحث المتقدم (الذي يدعم الـ SQL CONCAT الذي كتبناه في الـ Service)
   Route::post('/search', [GuestController::class, 'search'])
             ->middleware('role:branch_reception|hq_admin|hq_supervisor|hq_security|hq_auditor,api');
        
        // مسار خاص للتدقيق في "بصمة النزيل" (Fingerprint Check)
        Route::post('/verify-hashes', [GuestController::class, 'verifyHashes'])
             ->middleware('role:hq_security|hq_admin,api');

        // فك قفل النزيل (المهم جداً للنزلاء الذين تم تدقيقهم)
        Route::patch('/{guest}/unlock', [GuestController::class, 'unlock'])
             ->middleware('role:hq_admin|hq_security|hq_auditor,api');
    });

    Route::apiResource('guests', GuestController::class);

    // 3. منظومة الأمن والقائمة السوداء (Security Command Center)
    Route::prefix('security')->group(function () {
        
        // رادار التنبيهات (Real-time Alerts Radar)
        Route::middleware('role:hq_admin|hq_security|hq_auditor,api')->group(function () {
            Route::get('/blacklist', [SecurityBlacklistController::class, 'index']);
            Route::get('/notifications', [SecurityBlacklistController::class, 'getNotifications']);
            Route::get('/notifications/unread-count', [SecurityBlacklistController::class, 'unreadCount']); // مسار جديد للعداد
            Route::patch('/notifications/{id}/read', [SecurityBlacklistController::class, 'markAsRead']);
        });

        // إدارة القائمة السوداء (إضافة هاشات جديدة)
        Route::middleware('role:hq_admin|hq_security,api')->group(function () {
            Route::post('/blacklist/add', [SecurityBlacklistController::class, 'store']);
            Route::delete('/blacklist/{id}', [SecurityBlacklistController::class, 'destroy']);
        });
    });

    // 4. محرك الحجوزات (Reservations Engine)
    Route::prefix('reservations')->group(function () {
        // العرض اليومي والفلترة حسب الفرع
        Route::get('/daily-list', [ReservationController::class, 'dailyList']);
        
        // الخروج (Check-out) - الذي يحرر الغرفة ويتحقق من الـ is_locked
        Route::post('/{reservation}/checkout', [ReservationController::class, 'checkOut']);
        
        // عرض الوثائق (التي يتم جلبها من الـ Private Storage عبر الـ Service)
        Route::get('/{reservation}/documents/{document}', [ReservationController::class, 'viewDocument'])
             ->middleware('role:hq_admin|hq_security|hq_auditor,api');

        // التحكم في القفل الأمني للحجز
        Route::patch('/{id}/toggle-lock', [ReservationController::class, 'toggleLock'])
             ->middleware('role:hq_admin|hq_security,api');

        // استعادة المحذوفات (Soft Deletes)
        Route::post('/{id}/restore', [ReservationController::class, 'restore'])
             ->middleware('role:hq_admin|hq_auditor,api');
    });

    Route::apiResource('reservations', ReservationController::class);

    // 5. إدارة الغرف (Rooms Management)
    Route::prefix('rooms')->group(function () {
        Route::get('/', [RoomController::class, 'index']);
        Route::get('/logs', [RoomController::class, 'logs'])
             ->middleware('role:hq_admin|hq_auditor,api');
        
        Route::patch('/{room}/status', [RoomController::class, 'updateStatus'])
             ->middleware('role:hq_admin|hq_supervisor|branch_reception,api');
    });

    // 6. الإدارة والرقابة (HQ Admin Panel)
    Route::middleware('role:hq_admin,api')->group(function () {
        Route::apiResource('branches', BranchController::class);
        Route::apiResource('users', UserController::class);
        Route::get('/system-audit-logs', [UserController::class, 'auditLogs']); // سجل العمليات الشامل
    });

    Route::post('/logout', [AuthController::class, 'logout']);
});