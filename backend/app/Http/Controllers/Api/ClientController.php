<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Client::class);

        $q = strtolower((string) $request->query('search', ''));
        $query = Client::query()
            ->when($q, fn ($query) => $query->where('name', 'like', "%$q%")->orWhere('phone', 'like', "%$q%")->orWhere('civil_id', 'like', "%$q%"))
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

    public function statement(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        $rows = JournalEntry::with('account')
            ->whereHas('account', fn ($query) => $query->where('code', '1100'))
            ->where('party_type', 'client')
            ->where('party_id', $client->id)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
        $running = 0;

        return response()->json([
            'client' => $this->clientPayload($client),
            'total_purchases' => (float) Operation::where('client_id', $client->id)->where('status', '!=', 'cancelled')->sum('client_price'),
            'paid' => $this->accounting->clientReceiptsTotal($client->id),
            'balance' => $this->accounting->clientBalance($client->id),
            'rows' => $rows->map(function (JournalEntry $journal) use (&$running) {
                $running += (float) $journal->debit - (float) $journal->credit;

                return $this->journalPayload($journal) + ['balance' => round($running, 3)];
            }),
        ]);
    }
}
