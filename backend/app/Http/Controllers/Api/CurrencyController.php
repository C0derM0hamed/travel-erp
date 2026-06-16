<?php

namespace App\Http\Controllers\Api;

use App\Models\Currency;
use App\Services\ActivityLogger;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CurrencyController extends ApiController
{
    public function index(CurrencyService $currencies): JsonResponse
    {
        Gate::authorize('viewAny', Currency::class);

        $default = $currencies->defaultCurrency();

        return response()->json([
            'data' => Currency::query()->orderBy('code')->get()->map(fn (Currency $currency) => $this->payload($currency, $default->id))->values(),
            'default_currency' => $currencies->payload($default),
        ]);
    }

    public function store(Request $request, CurrencyService $currencies, ActivityLogger $logger): JsonResponse
    {
        Gate::authorize('create', Currency::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/', Rule::unique('currencies', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $currency = Currency::query()->create([
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'symbol' => $data['symbol'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $logger->log('currency.created', $currency, ['code' => $currency->code], $request->user()->id);

        return response()->json($this->payload($currency, $currencies->defaultCurrency()->id), 201);
    }

    public function update(Request $request, Currency $currency, CurrencyService $currencies, ActivityLogger $logger): JsonResponse
    {
        Gate::authorize('update', $currency);

        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/', Rule::unique('currencies', 'code')->ignore($currency->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'symbol' => ['sometimes', 'required', 'string', 'max:16'],
        ]);

        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $currency->update($data);
        $logger->log('currency.updated', $currency, array_keys($data), $request->user()->id);

        return response()->json($this->payload($currency->fresh(), $currencies->defaultCurrency()->id));
    }

    public function activate(Request $request, Currency $currency, CurrencyService $currencies, ActivityLogger $logger): JsonResponse
    {
        Gate::authorize('update', $currency);

        $currency->update(['is_active' => true]);
        $logger->log('currency.activated', $currency, ['code' => $currency->code], $request->user()->id);

        return response()->json($this->payload($currency->fresh(), $currencies->defaultCurrency()->id));
    }

    public function deactivate(Request $request, Currency $currency, CurrencyService $currencies, ActivityLogger $logger): JsonResponse
    {
        Gate::authorize('update', $currency);

        $currencies->deactivate($currency);
        $logger->log('currency.deactivated', $currency, ['code' => $currency->code], $request->user()->id);

        return response()->json($this->payload($currency->fresh(), $currencies->defaultCurrency()->id));
    }

    public function setDefault(Request $request, Currency $currency, CurrencyService $currencies, ActivityLogger $logger): JsonResponse
    {
        Gate::authorize('update', $currency);

        $currencies->setDefault($currency);
        $logger->log('currency.default_set', $currency, ['code' => $currency->code], $request->user()->id);

        return response()->json($this->payload($currency->fresh(), $currency->id));
    }

    private function payload(Currency $currency, int $defaultId): array
    {
        return [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
            'is_active' => (bool) $currency->is_active,
            'is_default' => (int) $currency->id === $defaultId,
        ];
    }
}
