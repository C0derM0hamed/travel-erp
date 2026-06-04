<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Operation;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Policies\ClientPolicy;
use App\Policies\OperationPolicy;
use App\Policies\ServicePolicy;
use App\Policies\UserPolicy;
use App\Policies\VendorPolicy;
use App\Policies\VoucherPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Client::class => ClientPolicy::class,
        Operation::class => OperationPolicy::class,
        Service::class => ServicePolicy::class,
        User::class => UserPolicy::class,
        Vendor::class => VendorPolicy::class,
        Voucher::class => VoucherPolicy::class,
    ];

    public function boot(): void
    {
        Gate::before(fn (User $user) => $user->role === 'admin' ? true : null);
        Gate::define('create-op', fn (User $user) => $user->canPerform('create_op'));
        Gate::define('cancel-op', fn (User $user) => $user->canPerform('cancel_op'));
        Gate::define('update-op-status', fn (User $user) => $user->canPerform('update_op_status'));
        Gate::define('create-voucher', fn (User $user) => $user->canPerform('create_voucher'));
        Gate::define('write-settings', fn (User $user) => $user->role === 'admin');
        Gate::define('viewReports', fn (User $user) => in_array($user->role, ['admin', 'sales', 'accountant', 'auditor'], true));
    }
}
