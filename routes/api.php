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

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:refresh');

if (app()->environment('local')) {

    Route::get('/debug-auth', function (Request $r) {
        return response()->json([
            'has_cookie_access_token' => $r->hasCookie('access_token'),
            'cookie_prefix' => $r->cookie('access_token') ? substr((string)$r->cookie('access_token'), 0, 25) : null,
            'authorization_header' => $r->header('Authorization'),
        ]);
    });

    Route::middleware(['auth:sanctum'])->get('/debug-auth-user', function (Request $r) {
        return response()->json([
            'auth_check' => auth()->check(),
            'auth_id'    => auth()->id(),
            'user'       => $r->user() ? [
                'id'    => $r->user()->id,
                'email' => $r->user()->email,
                'name'  => $r->user()->name,
            ] : null,
        ]);
    });
}

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/user-status', function (Request $request) {
        $u = $request->user();
        $u->loadMissing(['branch:id,name', 'roles']);

        return response()->json([
            'status' => 'success',
            'user'   => [
                'id'     => $u->id,
                'name'   => $u->name,
                'email'  => $u->email,
                'roles'  => $u->getRoleNames(),
                'branch' => $u->branch_id,
                'status' => $u->status,
            ]
        ]);
    });

    Route::prefix('security')->group(function () {

        Route::middleware('role:hq_admin|hq_security|hq_auditor|hq_supervisor')->group(function () {

            Route::get('/blacklist', [SecurityBlacklistController::class, 'index']);
            Route::get('/notifications', [SecurityBlacklistController::class, 'getNotifications']);
            Route::get('/notifications/unread-count', [SecurityBlacklistController::class, 'unreadCount']);

            Route::patch('/notifications/{id}/read', [SecurityBlacklistController::class, 'markAsRead'])
                ->whereNumber('id');
        });

        Route::middleware('role:hq_admin|hq_security')->group(function () {
            Route::post('/blacklist/add', [SecurityBlacklistController::class, 'store'])
                ->middleware('throttle:20,1');
        });
    });

    Route::prefix('reservations')->group(function () {

        // ===== Extra endpoints (لازم قبل resource) =====
        Route::get('/active', [ReservationController::class, 'activeOccupancy']);

        Route::get('/today-checkins', [ReservationController::class, 'todayCheckins']);
        Route::get('/today-checkouts', [ReservationController::class, 'todayCheckouts']);
        Route::get('/due-checkouts-today', [ReservationController::class, 'dueCheckoutsToday']);

        // ✅ LITE (الأسرع)
        Route::get('/today-checkins-lite', [ReservationController::class, 'todayCheckinsLite']);
        Route::get('/today-checkouts-lite', [ReservationController::class, 'todayCheckoutsLite']);
        Route::get('/due-checkouts-today-lite', [ReservationController::class, 'dueCheckoutsTodayLite']);

        Route::get('/stats/daily', [ReservationController::class, 'dailyStats']);

        Route::get('/documents/{document}/view', [ReservationController::class, 'showDocument'])
            ->middleware('role:branch_reception|hq_admin|hq_security|hq_auditor|hq_supervisor')
            ->name('doc.view');

        Route::post('/{reservation}/audit', [ReservationController::class, 'audit'])
            ->middleware(['role:hq_admin|hq_supervisor|hq_security|hq_auditor', 'throttle:30,1'])
            ->whereNumber('reservation');

        Route::post('/{reservation}/checkout', [ReservationController::class, 'checkOut'])
            ->middleware('throttle:60,1')
            ->whereNumber('reservation');

        Route::patch('/{reservation}/toggle-lock', [ReservationController::class, 'toggleLock'])
            ->middleware(['role:hq_admin|hq_security|hq_supervisor', 'throttle:30,1'])
            ->whereNumber('reservation');

        Route::get('/{reservation}/documents', [ReservationController::class, 'viewDocuments'])
            ->middleware('role:branch_reception|hq_admin|hq_security|hq_auditor|hq_supervisor')
            ->whereNumber('reservation');
    });

    // ✅ مهم جداً: خلي الـ resource يقبل أرقام فقط حتى ما يلتقط today-checkouts-lite
    Route::apiResource('reservations', ReservationController::class)
        ->where(['reservation' => '[0-9]+']);

    Route::prefix('guests')->group(function () {

        Route::post('/search', [GuestController::class, 'search'])
            ->middleware('throttle:120,1');

        Route::post('/verify-hashes', [GuestController::class, 'verifyHashes'])
            ->middleware(['role:hq_security|hq_admin', 'throttle:30,1']);
    });

    Route::apiResource('guests', GuestController::class);

    Route::prefix('rooms')->group(function () {

        Route::get('/', [RoomController::class, 'index']);

        Route::patch('/{room}/status', [RoomController::class, 'updateStatus'])
            ->middleware(['role:hq_admin|hq_supervisor|branch_reception', 'throttle:60,1']);
    });

    Route::middleware('role:hq_admin')->group(function () {

        Route::apiResource('branches', BranchController::class);
        Route::apiResource('users', UserController::class);

        Route::get('/audit-logs', [UserController::class, 'systemLogs'])
            ->middleware('throttle:30,1');

        Route::get('/reports/global-daily', [ReservationController::class, 'globalDailyStats'])
            ->middleware('throttle:30,1');
    });

    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('throttle:30,1');
});