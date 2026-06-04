<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TravelErpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_login_and_bootstrap_returns_frontend_shape(): void
    {
        $password = env('SEED_USER_PASSWORD', 'travel-erp-test-secret');
        $this->postJson('/api/login', ['email' => 'admin@travel.kw', 'password' => $password])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $this->actingAs(User::where('email', 'admin@travel.kw')->first())
            ->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonStructure(['user', 'users', 'services', 'safes', 'metrics'])
            ->assertJsonMissingPath('operations')
            ->assertJsonMissingPath('vouchers')
            ->assertJsonMissingPath('clients')
            ->assertJsonMissingPath('vendors');

        $this->actingAs(User::where('email', 'admin@travel.kw')->first())
            ->getJson('/api/operations?per_page=20')
            ->assertOk()
            ->assertJsonCount(13, 'data');

        $this->actingAs(User::where('email', 'admin@travel.kw')->first())
            ->getJson('/api/vouchers?per_page=20')
            ->assertOk()
            ->assertJsonCount(10, 'data');
    }

    public function test_operation_creation_posts_journal_and_initial_receipt(): void
    {
        $sales = User::where('role', 'sales')->first();
        $beforeJournal = JournalEntry::count();

        $response = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'currency' => 'KWD',
            'client_price' => 100,
            'vendor_cost' => 75,
            'initial_payment' => 50,
            'payment_method' => 'cash',
            'notes' => 'Test operation',
        ]);

        $response->assertCreated()->assertJsonPath('profit', 25);
        $this->assertDatabaseHas('operations', ['ref' => 'OP-014', 'profit' => 25]);
        $this->assertDatabaseHas('vouchers', ['ref' => 'RV-011', 'type' => 'receipt', 'amount' => 50]);
        $this->assertSame($beforeJournal + 6, JournalEntry::count());
        $this->assertEquals(JournalEntry::sum('debit'), JournalEntry::sum('credit'));
    }

    public function test_cancel_operation_posts_reversal_and_permissions_are_enforced(): void
    {
        $auditor = User::where('role', 'auditor')->first();
        $sales = User::where('role', 'sales')->first();
        $operation = Operation::where('status', 'new')->first();

        $this->actingAs($auditor)->postJson("/api/operations/{$operation->id}/cancel")->assertForbidden();

        $accountant = User::where('role', 'accountant')->first();
        $this->actingAs($sales)->postJson("/api/operations/{$operation->id}/cancel")->assertForbidden();

        $before = JournalEntry::count();
        $this->actingAs($accountant)->postJson("/api/operations/{$operation->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame($before + 4, JournalEntry::count());
        $this->assertEquals(JournalEntry::sum('debit'), JournalEntry::sum('credit'));
    }

    public function test_voucher_and_report_endpoints_work(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'payment',
            'party_type' => 'vendor',
            'party_id' => 2,
            'amount' => 20,
            'currency' => 'KWD',
            'method' => 'bank',
            'safe_id' => 2,
            'description' => 'Test payment',
        ])->assertCreated()->assertJsonPath('ref', 'PV-011');

        $this->actingAs($accountant)->getJson('/api/reports/clients-debt')->assertOk()->assertJsonStructure(['rows', 'totalDebt']);
        $this->actingAs($accountant)->getJson('/api/journal')->assertOk()->assertJsonPath('totals.balanced', true);
    }

    public function test_safes_balances_and_frontend_route(): void
    {
        $admin = User::where('role', 'admin')->first();
        $safeBalance = app(AccountingService::class)->safeBalance(1);
        $this->assertEquals(5800.0, $safeBalance);

        $this->actingAs($admin)->getJson('/api/safes')->assertOk()->assertJsonPath('data.0.balance', 5800);
        $this->get('/')->assertOk()->assertSee('API INTEGRATION BRIDGE');
    }
}
