<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Voucher;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClientController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Client::class);

        $q = strtolower((string) $request->query('search', ''));
        $query = Client::query();
        $this->applyHiddenFilter($request, $query);
        $query->when($q, fn ($query) => $query->where('name', 'like', "%$q%")
                ->orWhere('phone', 'like', "%$q%")
                ->orWhere('civil_id', 'like', "%$q%")
                ->orWhere('email', 'like', "%$q%")
                ->orWhere('nationality', 'like', "%$q%"))
            ->orderBy('id');

        return $this->paginatedResponse($request, $query, fn (Client $client) => $this->clientPayload($client));
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        Gate::authorize('create', Client::class);

        $client = Client::create($request->validated());
        app(ActivityLogger::class)->log('client.created', $client, ['name' => $client->name], $request->user()->id);

        return response()->json($this->clientPayload($client), 201);
    }

    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $client->update($request->validated());
        app(ActivityLogger::class)->log('client.updated', $client, ['name' => $client->name], $request->user()->id);

        return response()->json($this->clientPayload($client->fresh()));
    }

    public function destroy(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('delete', $client);
        $this->ensureClientDeletable($client);

        $name = $client->name;
        $clientId = $client->id;
        $client->delete();
        app(ActivityLogger::class)->log('client.deleted', null, ['id' => $clientId, 'name' => $name], $request->user()->id);

        return response()->json(['message' => 'تم حذف العميل بنجاح']);
    }

    public function hide(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('hide', $client);

        if ($client->is_hidden) {
            return response()->json($this->clientPayload($client));
        }

        $client->update(['is_hidden' => true]);
        app(ActivityLogger::class)->log('client.hidden', $client, ['name' => $client->name], $request->user()->id);

        return response()->json($this->clientPayload($client->fresh()));
    }

    public function restore(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('restore', $client);

        if (! $client->is_hidden) {
            return response()->json($this->clientPayload($client));
        }

        $client->update(['is_hidden' => false]);
        app(ActivityLogger::class)->log('client.restored', $client, ['name' => $client->name], $request->user()->id);

        return response()->json($this->clientPayload($client->fresh()));
    }

    public function statement(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

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

        $rows = $rowsQuery->get();
        $running = 0;

        $opsQuery = Operation::where('client_id', $client->id)->where('status', '!=', 'cancelled');
        if ($request->filled('from')) {
            $opsQuery->whereDate('op_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $opsQuery->whereDate('op_date', '<=', $request->to);
        }

        return response()->json([
            'client' => $this->clientPayload($client),
            'total_purchases' => (float) (clone $opsQuery)->sum('client_price'),
            'paid' => $this->accounting->clientReceiptsTotal($client->id),
            'balance' => $this->accounting->clientBalance($client->id),
            'rows' => $rows->map(function (JournalEntry $journal) use (&$running) {
                $running += (float) $journal->debit - (float) $journal->credit;

                return $this->journalPayload($journal) + ['balance' => round($running, 3)];
            }),
        ]);
    }

    private function ensureClientDeletable(Client $client): void
    {
        if (Operation::where('client_id', $client->id)->exists()) {
            throw ValidationException::withMessages([
                'client' => 'لا يمكن حذف العميل لوجود عمليات مرتبطة به. ألغِ العمليات أولاً أو احتفظ بالسجل.',
            ]);
        }

        if (Voucher::where('party_type', 'client')->where('party_id', $client->id)->exists()) {
            throw ValidationException::withMessages([
                'client' => 'لا يمكن حذف العميل لوجود سندات مالية مرتبطة به.',
            ]);
        }

        if (JournalEntry::where('party_type', 'client')->where('party_id', $client->id)->exists()) {
            throw ValidationException::withMessages([
                'client' => 'لا يمكن حذف العميل لوجود قيود محاسبية مرتبطة به.',
            ]);
        }

        if (abs($this->accounting->clientBalance($client->id)) > 0.001) {
            throw ValidationException::withMessages([
                'client' => 'لا يمكن حذف العميل لوجود رصيد غير مسدد.',
            ]);
        }
    }
}
