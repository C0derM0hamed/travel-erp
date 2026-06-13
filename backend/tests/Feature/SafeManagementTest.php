<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Safe;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SafeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_can_create_cash_and_bank_safes(): void
    {
        $accountant = User::where('role', 'accountant')->first();

        $this->actingAsWithOffice($accountant)
            ->postJson('/api/safes', [
                'name' => 'صندوق فرعي',
                'type' => 'cash',
                'opening_balance' => 100,
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'صندوق فرعي')
            ->assertJsonPath('type', 'cash')
            ->assertJsonFragment(['account_code' => '1003']);

        $this->actingAsWithOffice($accountant)
            ->postJson('/api/safes', [
                'name' => 'بنك الخليج',
                'type' => 'bank',
                'opening_balance' => 500,
            ])
            ->assertCreated()
            ->assertJsonPath('type', 'bank');
    }

    public function test_can_update_and_toggle_safe(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $safe = Safe::first();

        $this->actingAsWithOffice($accountant)
            ->patchJson("/api/safes/{$safe->id}", ['name' => 'صندوق محدّث'])
            ->assertOk()
            ->assertJsonPath('name', 'صندوق محدّث');

        $this->actingAsWithOffice($accountant)
            ->patchJson("/api/safes/{$safe->id}/toggle")
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_safe_to_bank_transfer_updates_balances_without_creating_money(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $accounting = app(AccountingService::class);
        $cash = Safe::where('type', 'cash')->first();
        $bank = Safe::where('type', 'bank')->first();

        $cashBefore = $accounting->safeBalance($cash->id);
        $bankBefore = $accounting->safeBalance($bank->id);
        $totalBefore = $cashBefore + $bankBefore;

        $this->actingAsWithOffice($accountant)
            ->postJson('/api/safe-transfers', [
                'from_safe_id' => $cash->id,
                'to_safe_id' => $bank->id,
                'amount' => 200,
            ])
            ->assertCreated()
            ->assertJsonPath('amount', 200);

        $cashAfter = $accounting->safeBalance($cash->id);
        $bankAfter = $accounting->safeBalance($bank->id);

        $this->assertEqualsWithDelta($cashBefore - 200, $cashAfter, 0.001);
        $this->assertEqualsWithDelta($bankBefore + 200, $bankAfter, 0.001);
        $this->assertEqualsWithDelta($totalBefore, $cashAfter + $bankAfter, 0.001);
        $this->assertTrue($accounting->isJournalBalanced());
    }

    public function test_all_transfer_scenarios(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $accounting = app(AccountingService::class);

        $cash2 = $this->actingAsWithOffice($accountant)
            ->postJson('/api/safes', ['name' => 'صندوق 2', 'type' => 'cash', 'opening_balance' => 1000])
            ->assertCreated()
            ->json();

        $bank2 = $this->actingAsWithOffice($accountant)
            ->postJson('/api/safes', ['name' => 'بنك 2', 'type' => 'bank', 'opening_balance' => 2000])
            ->assertCreated()
            ->json();

        $cash1 = Safe::where('type', 'cash')->whereKeyNot($cash2['id'])->first();
        $bank1 = Safe::where('type', 'bank')->whereKeyNot($bank2['id'])->first();

        $scenarios = [
            ['from' => $cash1->id, 'to' => $cash2['id'], 'amount' => 50, 'label' => 'cash_to_cash'],
            ['from' => $cash2['id'], 'to' => $bank1->id, 'amount' => 75, 'label' => 'cash_to_bank'],
            ['from' => $bank1->id, 'to' => $cash1->id, 'amount' => 25, 'label' => 'bank_to_cash'],
            ['from' => $bank1->id, 'to' => $bank2['id'], 'amount' => 100, 'label' => 'bank_to_bank'],
        ];

        foreach ($scenarios as $scenario) {
            $fromBefore = $accounting->safeBalance($scenario['from']);
            $toBefore = $accounting->safeBalance($scenario['to']);

            $this->actingAsWithOffice($accountant)
                ->postJson('/api/safe-transfers', [
                    'from_safe_id' => $scenario['from'],
                    'to_safe_id' => $scenario['to'],
                    'amount' => $scenario['amount'],
                ], ['Idempotency-Key' => 'transfer-'.$scenario['label']])
                ->assertCreated();

            $this->assertEqualsWithDelta($fromBefore - $scenario['amount'], $accounting->safeBalance($scenario['from']), 0.001, $scenario['label'].' from');
            $this->assertEqualsWithDelta($toBefore + $scenario['amount'], $accounting->safeBalance($scenario['to']), 0.001, $scenario['label'].' to');
        }
    }

    public function test_transfer_rejects_insufficient_balance_and_same_account(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $cash = Safe::where('type', 'cash')->first();
        $bank = Safe::where('type', 'bank')->first();
        $balance = app(AccountingService::class)->safeBalance($cash->id);

        $this->actingAsWithOffice($accountant)
            ->postJson('/api/safe-transfers', [
                'from_safe_id' => $cash->id,
                'to_safe_id' => $cash->id,
                'amount' => 10,
            ])
            ->assertUnprocessable();

        $this->actingAsWithOffice($accountant)
            ->postJson('/api/safe-transfers', [
                'from_safe_id' => $cash->id,
                'to_safe_id' => $bank->id,
                'amount' => $balance + 5000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_transfer_history_is_paginated_and_searchable(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $cash = Safe::where('type', 'cash')->first();
        $bank = Safe::where('type', 'bank')->first();

        $this->actingAsWithOffice($accountant)
            ->postJson('/api/safe-transfers', [
                'from_safe_id' => $cash->id,
                'to_safe_id' => $bank->id,
                'amount' => 10,
                'notes' => 'تحويل اختباري للبحث',
            ])
            ->assertCreated();

        $this->actingAsWithOffice($accountant)
            ->getJson('/api/safe-transfers?search=اختباري&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonFragment(['notes' => 'تحويل اختباري للبحث']);
    }

    public function test_cashflow_report_reflects_transfers(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $cash = Safe::where('type', 'cash')->first();
        $bank = Safe::where('type', 'bank')->first();
        $date = now()->toDateString();

        $this->actingAsWithOffice($accountant)
            ->postJson('/api/safe-transfers', [
                'from_safe_id' => $cash->id,
                'to_safe_id' => $bank->id,
                'amount' => 30,
                'transfer_date' => $date,
            ])
            ->assertCreated();

        $report = $this->actingAsWithOffice($accountant)
            ->getJson("/api/reports/cashflow?from={$date}&to={$date}")
            ->assertOk()
            ->json();

        $row = collect($report['rows'])->firstWhere('date', $date);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(30, (float) $row['inflow'], 0.001);
        $this->assertEqualsWithDelta(30, (float) $row['outflow'], 0.001);
        $this->assertEqualsWithDelta(0, (float) $row['net'], 0.001);
    }

    public function test_transfers_respect_office_isolation(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $otherOffice = Office::where('office_code', '!=', 'MAIN')->first();

        if (! $otherOffice) {
            $this->markTestSkipped('No secondary office in seed');
        }

        $otherSafe = Safe::withoutGlobalScopes()->where('office_id', $otherOffice->id)->first();
        $localSafe = Safe::where('type', 'cash')->first();

        $this->actingAsWithOffice($accountant)
            ->postJson('/api/safe-transfers', [
                'from_safe_id' => $localSafe->id,
                'to_safe_id' => $otherSafe->id,
                'amount' => 10,
            ])
            ->assertUnprocessable();
    }
}
