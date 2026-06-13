<?php

namespace App\Services\Exports;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Support\OfficeContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ExportQueryService
{
    public function operationsQuery(Request $request): Builder
    {
        $query = Operation::with(['client', 'service', 'vendor'])->visible()->orderByDesc('id');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('service') && $request->service !== 'all') {
            $query->where('service_id', $request->service);
        }
        if ($request->filled('from')) {
            $query->whereDate('op_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('op_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q
                ->where('ref', 'like', "%$search%")
                ->orWhere('notes', 'like', "%$search%")
                ->orWhere('status', 'like', "%$search%")
                ->orWhereHas('client', fn ($client) => $client
                    ->where('name', 'like', "%$search%")
                    ->orWhere('phone', 'like', "%$search%"))
                ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%$search%"))
                ->orWhereHas('service', fn ($service) => $service->where('name', 'like', "%$search%")));
        }

        return $query;
    }

    public function clientsQuery(Request $request): Builder
    {
        $search = strtolower((string) $request->query('search', ''));

        return Client::query()->visible()
            ->when($search, fn ($query) => $query->where('name', 'like', "%$search%")
                ->orWhere('phone', 'like', "%$search%")
                ->orWhere('civil_id', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")
                ->orWhere('nationality', 'like', "%$search%"))
            ->orderBy('id');
    }

    public function vendorsQuery(Request $request): Builder
    {
        $search = strtolower((string) $request->query('search', ''));

        return Vendor::query()
            ->when($search, fn ($query) => $query->where('name', 'like', "%$search%")
                ->orWhere('phone', 'like', "%$search%")
                ->orWhere('contact', 'like', "%$search%")
                ->orWhere('category', 'like', "%$search%"))
            ->orderBy('id');
    }

    public function vouchersQuery(Request $request): Builder
    {
        $query = Voucher::with(['safe', 'operation'])->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('from')) {
            $query->whereDate('voucher_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('voucher_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q
                ->where('ref', 'like', "%$search%")
                ->orWhere('description', 'like', "%$search%")
                ->orWhere(function ($partyQuery) use ($search) {
                    $partyQuery->where('party_type', 'client')
                        ->whereIn('party_id', fn ($sub) => $sub->select('id')->from('clients')->where('name', 'like', "%$search%"))
                        ->orWhere(function ($inner) use ($search) {
                            $inner->where('party_type', 'vendor')
                                ->whereIn('party_id', fn ($sub) => $sub->select('id')->from('vendors')->where('name', 'like', "%$search%"));
                        });
                }));
        }

        return $query;
    }

    public function journalQuery(Request $request): Builder
    {
        $query = JournalEntry::with('account')->orderBy('entry_date')->orderBy('id');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('ref', 'like', "%$search%")->orWhere('description', 'like', "%$search%"));
        }
        if ($request->filled('account') && $request->account !== 'all') {
            $query->whereHas('account', fn ($q) => $q->where('name', $request->account)->orWhere('code', $request->account));
        }
        if ($request->filled('from')) {
            $query->whereDate('entry_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('entry_date', '<=', $request->to);
        }

        return $query;
    }

    public function activityLogsQuery(Request $request): Builder
    {
        $user = $request->user();
        $query = ActivityLog::with(['user', 'office'])->orderByDesc('id');

        if ($user->role !== 'super_admin') {
            $officeId = app(OfficeContext::class)->id() ?? $user->office_id;
            if ($officeId) {
                $query->where('office_id', $officeId);
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }
        if ($request->filled('action') && $request->action !== 'all') {
            if ($request->action === 'operations') {
                $query->where('action', 'like', 'operation.%');
            } else {
                $query->where('action', $request->action);
            }
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q
                ->where('action', 'like', "%$search%")
                ->orWhere('payload', 'like', "%$search%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%$search%")->orWhere('email', 'like', "%$search%"))
                ->orWhereHas('office', fn ($o) => $o->where('office_name', 'like', "%$search%")));
        }

        return $query;
    }

    public function clientStatementQuery(Client $client, Request $request): Builder
    {
        $rowsQuery = JournalEntry::with('account')
            ->whereHas('account', fn ($query) => $query->where('code', '1100'))
            ->where('party_type', 'client')
            ->where('party_id', $client->id)
            ->orderBy('entry_date')
            ->orderBy('id');

        if ($request->filled('from')) {
            $rowsQuery->whereDate('entry_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $rowsQuery->whereDate('entry_date', '<=', $request->to);
        }

        return $rowsQuery;
    }

    public function vendorStatementQuery(Vendor $vendor, Request $request): Builder
    {
        $rowsQuery = JournalEntry::with('account')
            ->whereHas('account', fn ($query) => $query->where('code', '2100'))
            ->where('party_type', 'vendor')
            ->where('party_id', $vendor->id)
            ->orderBy('entry_date')
            ->orderBy('id');

        if ($request->filled('from')) {
            $rowsQuery->whereDate('entry_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $rowsQuery->whereDate('entry_date', '<=', $request->to);
        }

        return $rowsQuery;
    }

    public function scopedUsersQuery(): Builder
    {
        $context = app(OfficeContext::class);
        $query = User::query()->orderBy('name');

        if (! $context->isSuperAdmin()) {
            $officeId = $context->id();
            if ($officeId) {
                $query->where('office_id', $officeId);
            }
        }

        return $query;
    }
}
