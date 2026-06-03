<?php

use App\Http\Controllers\TravelErpController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/me', [TravelErpController::class, 'me']);
    Route::get('/bootstrap', [TravelErpController::class, 'bootstrap']);
    Route::get('/dashboard', [TravelErpController::class, 'dashboard']);

    Route::get('/clients', [TravelErpController::class, 'clients']);
    Route::post('/clients', [TravelErpController::class, 'storeClient']);
    Route::get('/clients/{client}/statement', [TravelErpController::class, 'clientStatement']);

    Route::get('/vendors', [TravelErpController::class, 'vendors']);
    Route::post('/vendors', [TravelErpController::class, 'storeVendor']);
    Route::get('/vendors/{vendor}/statement', [TravelErpController::class, 'vendorStatement']);

    Route::get('/operations', [TravelErpController::class, 'operations']);
    Route::post('/operations', [TravelErpController::class, 'storeOperation']);
    Route::get('/operations/{operation}', [TravelErpController::class, 'operationShow']);
    Route::post('/operations/{operation}/cancel', [TravelErpController::class, 'cancelOperation']);
    Route::patch('/operations/{operation}/status', [TravelErpController::class, 'updateOperationStatus']);

    Route::get('/vouchers', [TravelErpController::class, 'vouchers']);
    Route::post('/vouchers', [TravelErpController::class, 'storeVoucher']);
    Route::get('/vouchers/{voucher}', [TravelErpController::class, 'voucherShow']);

    Route::get('/journal', [TravelErpController::class, 'journal']);
    Route::get('/safes', [TravelErpController::class, 'safes']);
    Route::get('/reports/{type}', [TravelErpController::class, 'reports']);

    Route::get('/users', [TravelErpController::class, 'users']);
    Route::patch('/services/{service}/toggle', [TravelErpController::class, 'toggleService']);
    Route::patch('/profile', [TravelErpController::class, 'updateProfile']);
});
