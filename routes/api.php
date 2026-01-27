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
        Route::get('/leaves/requests', [LeaveRequestController::class, 'index']);
        Route::post('/leaves/requests', [LeaveRequestController::class, 'store']);
        Route::delete('/leaves/requests/{id}', [LeaveRequestController::class, 'destroy']);

        Route::get('/overtime/requests', [OvertimeRequestController::class, 'index']);
        Route::post('/overtime/requests', [OvertimeRequestController::class, 'store']);
        Route::delete('/overtime/requests/{id}', [OvertimeRequestController::class, 'destroy']);
    });
});
