<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Office;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\SafeTransfer;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Policies\ClientPolicy;
use App\Policies\CurrencyPolicy;
use App\Policies\OfficePolicy;
use App\Policies\OperationPolicy;
use App\Policies\SafePolicy;
use App\Policies\SafeTransferPolicy;
use App\Policies\ServicePolicy;
use App\Policies\UserPolicy;
use App\Policies\VendorPolicy;
use App\Policies\VoucherPolicy;
use App\Support\OfficeContext;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Client::class => ClientPolicy::class,
        Currency::class => CurrencyPolicy::class,
        Office::class => OfficePolicy::class,
        Operation::class => OperationPolicy::class,
        Safe::class => SafePolicy::class,
        SafeTransfer::class => SafeTransferPolicy::class,
        Service::class => ServicePolicy::class,
        User::class => UserPolicy::class,
        Vendor::class => VendorPolicy::class,
        Voucher::class => VoucherPolicy::class,
    ];

    public function boot(): void
    {
        Gate::before(fn (User $user) => $user->role === 'super_admin' ? true : null);
        Gate::define('create-op', fn (User $user) => $user->canPerform('create_op'));
        Gate::define('cancel-op', fn (User $user) => $user->canPerform('cancel_op'));
        Gate::define('update-op-status', fn (User $user) => $user->canPerform('update_op_status'));
        Gate::define('create-voucher', fn (User $user) => $user->canPerform('create_voucher'));
        Gate::define('write-settings', fn (User $user) => in_array($user->role, ['super_admin', 'admin'], true));
        Gate::define('manage-offices', fn (User $user) => in_array($user->role, ['super_admin', 'admin'], true));
        Gate::define('viewReports', fn (User $user) => in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant', 'auditor'], true));
    }
}
