<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TravelErpRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = (string) env('SEED_USER_PASSWORD', 'travel-erp-test-secret');
        $this->seed();
    }

    public function test_login_and_all_roles_can_load_bootstrap(): void
    {
        $this->postJson('/api/login', ['email' => 'admin@travel.kw', 'password' => $this->password])
            ->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role', 'roleLabel', 'avatar']]);

        foreach (User::orderBy('id')->get() as $user) {
            $this->actingAs($user)
                ->getJson('/api/bootstrap')
                ->assertOk()
                ->assertJsonStructure(['users', 'services', 'vendors', 'clients', 'operations', 'vouchers', 'safes', 'metrics']);
        }
    }

    public function test_role_permissions_on_write_endpoints(): void
    {
        $auditor = User::where('role', 'auditor')->first();
        $sales = User::where('role', 'sales')->first();
        $accountant = User::where('role', 'accountant')->first();

        $this->actingAs($auditor)->postJson('/api/clients', ['name' => 'X', 'phone' => '1'])->assertForbidden();
        $this->actingAs($auditor)->postJson('/api/vendors', ['name' => 'Y', 'category' => 'other', 'phone' => '2'])->assertForbidden();

        $this->actingAs($sales)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => 1,
            'amount' => 10,
            'currency' => 'KWD',
            'method' => 'cash',
            'safe_id' => 1,
        ])->assertForbidden();

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => 1,
            'amount' => 5,
            'currency' => 'KWD',
            'method' => 'cash',
            'safe_id' => 1,
        ])->assertCreated();

        $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'currency' => 'KWD',
            'client_price' => 50,
            'vendor_cost' => 30,
            'initial_payment' => 0,
            'payment_method' => 'cash',
        ])->assertCreated();
    }

    public function test_reports_and_journal_remain_balanced_after_seed(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->getJson('/api/journal')->assertOk()->assertJsonPath('totals.balanced', true);
        $this->assertGreaterThan(0, count($response->json('data')));

        foreach (['operations', 'profit', 'aging', 'employee', 'cashflow', 'clients-debt', 'vendors-balance'] as $type) {
            $this->actingAs($admin)->getJson("/api/reports/{$type}")->assertOk();
        }

        $this->assertEquals(JournalEntry::sum('debit'), JournalEntry::sum('credit'));
    }

    public function test_frontend_has_no_embedded_demo_passwords(): void
    {
        $html = file_get_contents(base_path('../frontend/travelsystemv3.html'));

        $this->assertStringNotContainsString("password:'123456'", $html);
        $this->assertStringNotContainsString('login-hint', $html);
        $this->assertStringNotContainsString('generateJournal', $html);
    }
}
