<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\OfficeController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SafeController;
use App\Http\Controllers\Api\SafeTransferController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\ResetPasswordController;
use Illuminate\Support\Facades\Route;

Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])->middleware('throttle:5,1');
Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->middleware('throttle:5,1');

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
        Route::post('/clients/{client}/hide', [ClientController::class, 'hide']);
        Route::post('/clients/{client}/restore', [ClientController::class, 'restore']);
        Route::get('/clients/{client}/statement', [ClientController::class, 'statement']);

        Route::get('/vendors', [VendorController::class, 'index']);
        Route::post('/vendors', [VendorController::class, 'store'])->middleware('idempotency');
        Route::patch('/vendors/{vendor}', [VendorController::class, 'update']);
        Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy']);
        Route::get('/vendors/{vendor}/statement', [VendorController::class, 'statement']);

        Route::get('/operations', [OperationController::class, 'index']);
        Route::post('/operations', [OperationController::class, 'store'])->middleware('idempotency');
        Route::get('/operations/{operation}', [OperationController::class, 'show']);
        Route::get('/operations/{operation}/invoice-share', [OperationController::class, 'invoiceShare']);
        Route::patch('/operations/{operation}', [OperationController::class, 'update']);
        Route::post('/operations/{operation}/cancel', [OperationController::class, 'cancel'])->middleware('idempotency');
        Route::patch('/operations/{operation}/status', [OperationController::class, 'updateStatus']);
        Route::post('/operations/{operation}/hide', [OperationController::class, 'hide']);
        Route::post('/operations/{operation}/restore', [OperationController::class, 'restore']);

        Route::get('/vouchers', [VoucherController::class, 'index']);
        Route::post('/vouchers', [VoucherController::class, 'store'])->middleware('idempotency');
        Route::get('/vouchers/{voucher}', [VoucherController::class, 'show']);
        Route::post('/vouchers/{voucher}/void', [VoucherController::class, 'void'])->middleware('idempotency');

        Route::get('/journal', JournalController::class);
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
        Route::get('/activity-logs/actions', [ActivityLogController::class, 'actions']);
        Route::get('/safes', [SafeController::class, 'index']);
        Route::post('/safes', [SafeController::class, 'store'])->middleware('idempotency');
        Route::patch('/safes/{safe}', [SafeController::class, 'update']);
        Route::patch('/safes/{safe}/toggle', [SafeController::class, 'toggle']);

        Route::get('/safe-transfers', [SafeTransferController::class, 'index']);
        Route::post('/safe-transfers', [SafeTransferController::class, 'store'])->middleware('idempotency');
        Route::get('/safe-transfers/{safeTransfer}', [SafeTransferController::class, 'show']);
        Route::get('/reports/{type}', [ReportController::class, 'show']);

        Route::get('/exports/operations', [ExportController::class, 'operations']);
        Route::get('/exports/operations/{operation}', [ExportController::class, 'operation']);
        Route::get('/exports/operations/{operation}/invoice', [ExportController::class, 'operationInvoice']);
        Route::get('/exports/clients', [ExportController::class, 'clients']);
        Route::get('/exports/clients/{client}/statement', [ExportController::class, 'clientStatement']);
        Route::get('/exports/vendors', [ExportController::class, 'vendors']);
        Route::get('/exports/vendors/{vendor}/statement', [ExportController::class, 'vendorStatement']);
        Route::get('/exports/vouchers', [ExportController::class, 'vouchers']);
        Route::get('/exports/vouchers/{voucher}', [ExportController::class, 'voucher']);
        Route::get('/exports/journal', [ExportController::class, 'journal']);
        Route::get('/exports/reports/{type}', [ExportController::class, 'report']);
        Route::get('/exports/activity-logs', [ExportController::class, 'activityLogs']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store'])->middleware('idempotency');
        Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::patch('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::patch('/services/{service}/toggle', [ServiceController::class, 'toggle']);
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::patch('/profile/password', [ProfileController::class, 'updatePassword']);
    });

    Route::get('/offices', [OfficeController::class, 'index']);
    Route::post('/offices', [OfficeController::class, 'store'])->middleware('idempotency');
    Route::patch('/offices/{office}', [OfficeController::class, 'update']);
    Route::post('/offices/{office}/logo', [OfficeController::class, 'uploadLogo']);
    Route::delete('/offices/{office}/logo', [OfficeController::class, 'deleteLogo']);
});
