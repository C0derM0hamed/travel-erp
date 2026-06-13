<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\SafeTransfer;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Support\OfficeContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OfficeContext::class);
    }

    public function boot(): void
    {
        Route::bind('client', fn ($value) => Client::findOrFail($value));
        Route::bind('vendor', fn ($value) => Vendor::findOrFail($value));
        Route::bind('operation', fn ($value) => Operation::findOrFail($value));
        Route::bind('voucher', fn ($value) => Voucher::findOrFail($value));
        Route::bind('safe', fn ($value) => Safe::findOrFail($value));
        Route::bind('safeTransfer', fn ($value) => SafeTransfer::findOrFail($value));
        Route::bind('user', function ($value) {
            $user = User::findOrFail($value);
            $context = app(OfficeContext::class);
            $authUser = auth()->user();

            if ($authUser && $authUser->role !== 'super_admin' && (int) $user->office_id !== (int) $authUser->office_id) {
                abort(404);
            }

            return $user;
        });
    }
}
