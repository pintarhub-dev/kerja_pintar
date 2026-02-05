<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
// use App\Http\Controllers\Api\V1\OvertimeRequestController;
use App\Http\Controllers\Api\V1\LeaveTypeController;

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

Route::prefix('v1')->group(function () {
    // --- PUBLIC ROUTES (Tanpa Login) ---
    Route::post('auth/login', [AuthController::class, 'login']);

    // --- PROTECTED ROUTES (Butuh Token) ---
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('user/me', [AuthController::class, 'me']);
        Route::post('user/update-fcm-token', [AuthController::class, 'updateFcmToken']);

        // Route::middleware('check.subscription')->group(function () {
        Route::post('user/update', [AuthController::class, 'updateProfile']);
        Route::post('user/password', [AuthController::class, 'updatePassword']);
        // });

        Route::prefix('attendance')->controller(AttendanceController::class)->group(function () {
            Route::get('current-status', 'currentStatus');
            Route::get('history', 'history');
            Route::middleware('check.subscription')->group(function () {
                Route::post('clock-in', 'clockIn');
                Route::post('clock-out', 'clockOut');
            });
        });

        Route::get('/leave-types', [LeaveTypeController::class, 'index']);
        Route::get('/leave-balances', [LeaveBalanceController::class, 'index']);

        Route::prefix('leave')->controller(LeaveRequestController::class)->group(function () {
            Route::get('requests', 'index');
            Route::middleware('check.subscription')->group(function () {
                Route::post('requests', 'store');
                Route::put('requests/{id}', 'update');
                Route::delete('requests/{id}', 'destroy');
            });
        });

        // FITUR UNTUK KEMBANGAN MASA DEPAN
        // Route::middleware('check.subscription')->group(function () {
        // Route::prefix('overtimes')->controller(OvertimeRequestController::class)->group(function () {
        //     Route::get('requests', 'index');
        //     Route::post('requests', 'store');
        //     Route::delete('requests/{id}', 'destroy');
        // });
        // });
    });
});
