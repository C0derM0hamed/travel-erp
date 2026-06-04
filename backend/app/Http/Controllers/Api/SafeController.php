<?php

namespace App\Http\Controllers\Api;

use App\Models\JournalEntry;
use App\Models\Safe;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SafeController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        Gate::authorize('viewReports');

        return response()->json([
            'data' => Safe::with('account')->orderBy('id')->get()->map(fn (Safe $safe) => $this->safePayload($safe) + [
                'balance' => $this->accounting->safeBalance($safe->id),
                'movements' => $safe->account
                    ? JournalEntry::with('account')->where('account_id', $safe->account->id)->orderByDesc('entry_date')->take(10)->get()->map(fn (JournalEntry $journal) => $this->journalPayload($journal))
                    : [],
            ]),
        ]);
    }
}
