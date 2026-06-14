<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\PublicInvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response(file_get_contents(base_path('../frontend/travelsystemv3.html')))
        ->header('Content-Type', 'text/html; charset=UTF-8')
        ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
});

Route::get('/travel-erp', function () {
    return response(file_get_contents(base_path('../frontend/travelsystemv3.html')))
        ->header('Content-Type', 'text/html; charset=UTF-8')
        ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
});

Route::get('/travel-erp-api-bridge.js', function () {
    return response(file_get_contents(base_path('../frontend/travel-erp-api-bridge.js')))
        ->header('Content-Type', 'application/javascript; charset=UTF-8');
});

Route::post('/api/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/api/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::get('/invoice/{operation}', [PublicInvoiceController::class, 'show'])
    ->middleware('signed')
    ->name('invoice.public');
