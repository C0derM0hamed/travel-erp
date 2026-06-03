<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(fn (User $user) => $user->role === 'admin' ? true : null);
        Gate::define('create-op', fn (User $user) => $user->canPerform('create_op'));
        Gate::define('cancel-op', fn (User $user) => $user->canPerform('cancel_op'));
        Gate::define('update-op-status', fn (User $user) => $user->canPerform('update_op_status'));
        Gate::define('create-voucher', fn (User $user) => $user->canPerform('create_voucher'));
        Gate::define('write-settings', fn (User $user) => $user->role === 'admin');
    }
}
