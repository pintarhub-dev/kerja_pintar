<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\OvertimeRequestController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('v1')->group(function () {
    // --- PUBLIC ROUTES (Tanpa Login) ---
    Route::post('auth/login', [AuthController::class, 'login']);

    // --- PROTECTED ROUTES (Butuh Token) ---
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::prefix('attendance')->controller(AttendanceController::class)->group(function () {
            Route::post('clock-in', 'clockIn');
            Route::post('clock-out', 'clockOut');
        });

        Route::get('/leaves/balances', [LeaveBalanceController::class, 'index']);

        Route::prefix('leaves')->controller(LeaveRequestController::class)->group(function () {
            Route::get('requests', 'index');
            Route::post('requests', 'store');
            Route::delete('requests/{id}', 'destroy');
        });

        Route::prefix('overtimes')->controller(OvertimeRequestController::class)->group(function () {
            Route::get('requests', 'index');
            Route::post('requests', 'store');
            Route::delete('requests/{id}', 'destroy');
        });
    });
});
