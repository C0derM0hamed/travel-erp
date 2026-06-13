<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\OfficeController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SafeController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\VoucherController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'user.active'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    Route::middleware(['office.context'])->group(function () {
        Route::get('/bootstrap', BootstrapController::class);
        Route::get('/dashboard', DashboardController::class);
        Route::post('/session/office', [OfficeController::class, 'switchOffice']);

        Route::get('/clients', [ClientController::class, 'index']);
        Route::post('/clients', [ClientController::class, 'store'])->middleware('idempotency');
        Route::patch('/clients/{client}', [ClientController::class, 'update']);
        Route::delete('/clients/{client}', [ClientController::class, 'destroy']);
        Route::get('/clients/{client}/statement', [ClientController::class, 'statement']);

        Route::get('/vendors', [VendorController::class, 'index']);
        Route::post('/vendors', [VendorController::class, 'store'])->middleware('idempotency');
        Route::patch('/vendors/{vendor}', [VendorController::class, 'update']);
        Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy']);
        Route::get('/vendors/{vendor}/statement', [VendorController::class, 'statement']);

        Route::get('/operations', [OperationController::class, 'index']);
        Route::post('/operations', [OperationController::class, 'store'])->middleware('idempotency');
        Route::get('/operations/{operation}', [OperationController::class, 'show']);
        Route::patch('/operations/{operation}', [OperationController::class, 'update']);
        Route::post('/operations/{operation}/cancel', [OperationController::class, 'cancel'])->middleware('idempotency');
        Route::patch('/operations/{operation}/status', [OperationController::class, 'updateStatus']);

        Route::get('/vouchers', [VoucherController::class, 'index']);
        Route::post('/vouchers', [VoucherController::class, 'store'])->middleware('idempotency');
        Route::get('/vouchers/{voucher}', [VoucherController::class, 'show']);
        Route::post('/vouchers/{voucher}/void', [VoucherController::class, 'void'])->middleware('idempotency');

        Route::get('/journal', JournalController::class);
        Route::get('/safes', SafeController::class);
        Route::get('/reports/{type}', [ReportController::class, 'show']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store'])->middleware('idempotency');
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::patch('/services/{service}/toggle', [ServiceController::class, 'toggle']);
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::patch('/profile/password', [ProfileController::class, 'updatePassword']);
    });

    Route::get('/offices', [OfficeController::class, 'index']);
    Route::post('/offices', [OfficeController::class, 'store'])->middleware('idempotency');
    Route::patch('/offices/{office}', [OfficeController::class, 'update']);
});
