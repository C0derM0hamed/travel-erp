<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\Service;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TravelErpProductionAuditTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = (string) env('SEED_USER_PASSWORD', 'travel-erp-test-secret');
        $this->seed();
    }

    public function test_duplicate_client_rejected_at_api_and_db(): void
    {
        $sales = User::where('role', 'sales')->first();

        $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Duplicate Test',
            'phone' => '99001122',
        ])->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseCount('clients', 8);
    }

    public function test_phone_format_variants_rejected_as_duplicates(): void
    {
        $sales = User::where('role', 'sales')->first();

        $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Format Dup',
            'phone' => '+96599001122',
        ])->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Format Dup 2',
            'phone' => '099001122',
        ])->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    public function test_duplicate_vendor_rejected(): void
    {
        $sales = User::where('role', 'sales')->first();

        $this->actingAs($sales)->postJson('/api/vendors', [
            'name' => 'الخطوط الجوية الكويتية',
            'category' => 'airline',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_duplicate_civil_id_rejected(): void
    {
        $sales = User::where('role', 'sales')->first();

        $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Another Person',
            'phone' => '90000001',
            'civil_id' => '280123456789',
        ])->assertStatus(422)->assertJsonValidationErrors(['civil_id']);
    }

    public function test_invalid_operation_amounts_and_dates_rejected(): void
    {
        $sales = User::where('role', 'sales')->first();

        $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 0,
            'vendor_cost' => 10,
        ])->assertStatus(422);

        $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 100,
            'vendor_cost' => 150,
        ])->assertStatus(422)->assertJsonValidationErrors(['vendor_cost']);

        $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 100,
            'vendor_cost' => 10,
            'initial_payment' => 150,
        ])->assertStatus(422)->assertJsonValidationErrors(['initial_payment']);

        $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 100,
            'vendor_cost' => 10,
            'date' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['date']);
    }

    public function test_voucher_overpayment_rejected(): void
    {
        $sales = User::where('role', 'sales')->first();
        $accountant = User::where('role', 'accountant')->first();

        $client = $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Overpay Client',
            'phone' => '90000333',
        ])->assertCreated();
        $clientId = $client->json('id');

        $op = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => $clientId,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 100,
            'vendor_cost' => 60,
            'initial_payment' => 0,
        ])->assertCreated();

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => $clientId,
            'amount' => 80,
            'safe_id' => 1,
            'operation_id' => $op->json('id'),
        ])->assertCreated();

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => $clientId,
            'amount' => 30,
            'safe_id' => 1,
            'operation_id' => $op->json('id'),
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->assertEqualsWithDelta(20, app(AccountingService::class)->clientBalance($clientId), 0.01);
    }

    public function test_payment_cannot_overdraw_safe(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $safeBalance = app(AccountingService::class)->safeBalance(1);

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'payment',
            'party_type' => 'general',
            'amount' => $safeBalance + 1,
            'safe_id' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_vendor_payment_is_capped_to_linked_operation_outstanding(): void
    {
        $sales = User::where('role', 'sales')->first();
        $accountant = User::where('role', 'accountant')->first();

        $client = $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Vendor Cap Client',
            'phone' => '90000444',
        ])->assertCreated();

        $small = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => $client->json('id'),
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 200,
            'vendor_cost' => 100,
        ])->assertCreated();

        $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => $client->json('id'),
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 500,
            'vendor_cost' => 400,
        ])->assertCreated();

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'payment',
            'party_type' => 'vendor',
            'party_id' => 1,
            'amount' => 150,
            'safe_id' => 2,
            'operation_id' => $small->json('id'),
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_dashboard_overdue_excludes_fully_paid_operation(): void
    {
        $sales = User::where('role', 'sales')->first();

        $client = $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Paid Overdue Client',
            'phone' => '90000445',
        ])->assertCreated();

        $op = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => $client->json('id'),
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 100,
            'vendor_cost' => 0,
            'initial_payment' => 100,
            'date' => now()->subDay()->toDateString(),
        ])->assertCreated();

        $ids = collect($this->actingAs($sales)->getJson('/api/dashboard')->assertOk()->json('overdue_operations'))->pluck('id');
        $this->assertFalse($ids->contains($op->json('id')));
    }

    public function test_status_workflow_and_auto_complete_after_settlement(): void
    {
        $sales = User::where('role', 'sales')->first();
        $accountant = User::where('role', 'accountant')->first();

        $client = $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Status Flow Client',
            'phone' => '90000446',
        ])->assertCreated();

        $op = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => $client->json('id'),
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 100,
            'vendor_cost' => 50,
        ])->assertCreated();

        $this->actingAs($sales)->patchJson("/api/operations/{$op->json('id')}/status", ['status' => 'processing'])
            ->assertOk()
            ->assertJsonPath('status', 'processing');

        $this->actingAs($accountant)->patchJson("/api/operations/{$op->json('id')}/status", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => $client->json('id'),
            'amount' => 100,
            'safe_id' => 1,
            'operation_id' => $op->json('id'),
        ])->assertCreated();

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'payment',
            'party_type' => 'vendor',
            'party_id' => 1,
            'amount' => 50,
            'safe_id' => 1,
            'operation_id' => $op->json('id'),
        ])->assertCreated();

        $this->assertSame('completed', Operation::find($op->json('id'))->status);
    }

    public function test_cashflow_report_includes_all_safes(): void
    {
        $accountant = User::where('role', 'accountant')->first();

        $safe = Safe::create(['name' => 'صندوق إضافي', 'type' => 'cash', 'currency' => 'KWD', 'opening_balance' => 10]);
        ChartOfAccount::create(['code' => '1003', 'name' => 'صندوق إضافي', 'type' => 'asset', 'safe_id' => $safe->id]);

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'general',
            'amount' => 25,
            'safe_id' => $safe->id,
        ])->assertCreated();

        $report = $this->actingAs($accountant)->getJson('/api/reports/cashflow')->assertOk();
        $this->assertTrue(collect($report->json('safes'))->pluck('id')->contains($safe->id));
        $this->assertTrue(collect($report->json('rows'))->contains(fn ($row) => abs((float) ($row['safes'][$safe->id] ?? 0) - 35) < 0.01));

        $lastRow = collect($report->json('rows'))->last();
        $cashSafeIds = Safe::where('type', 'cash')->pluck('id')->all();
        $cashFromSafes = collect($lastRow['safes'])->only($cashSafeIds)->sum();
        $this->assertEqualsWithDelta($cashFromSafes, (float) $lastRow['cash'], 0.01);
    }

    public function test_aging_buckets_operation_outstanding_by_operation_date(): void
    {
        $sales = User::where('role', 'sales')->first();

        $client = $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Aging Bucket Client',
            'phone' => '90000447',
        ])->assertCreated();

        $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => $client->json('id'),
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 100,
            'vendor_cost' => 50,
            'date' => now()->subDays(75)->toDateString(),
        ])->assertCreated();

        $row = collect($this->actingAs($sales)->getJson('/api/reports/aging')->assertOk()->json('rows'))
            ->firstWhere('name', 'Aging Bucket Client');

        $this->assertEqualsWithDelta(100, $row['b3'], 0.01);
        $this->assertEqualsWithDelta(100, $row['balance'], 0.01);
    }

    public function test_inactive_service_cannot_be_used_for_operations(): void
    {
        $admin = User::where('role', 'admin')->first();
        $sales = User::where('role', 'sales')->first();
        $service = Service::find(1);

        $this->actingAs($admin)->patchJson("/api/services/{$service->id}/toggle")->assertOk();
        $this->assertFalse($service->fresh()->active);

        $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 50,
            'vendor_cost' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors(['service_id']);

        $this->actingAs($admin)->patchJson("/api/services/{$service->id}/toggle")->assertOk();
    }

    public function test_voucher_requires_valid_party_and_rejects_cancelled_operation_link(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $cancelled = Operation::where('status', 'cancelled')->first();

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'amount' => 10,
            'safe_id' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['party_id']);

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => 9999,
            'amount' => 10,
            'safe_id' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['party_id']);

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => 1,
            'amount' => -5,
            'safe_id' => 1,
        ])->assertStatus(422);

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => 1,
            'amount' => 10,
            'safe_id' => 1,
            'operation_id' => $cancelled->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['operation_id']);
    }

    public function test_cancel_operation_reverses_linked_vouchers_and_stays_balanced(): void
    {
        $sales = User::where('role', 'sales')->first();
        $before = JournalEntry::count();

        $client = $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Cancel Reversal Client',
            'phone' => '90000222',
        ])->assertCreated();
        $clientId = $client->json('id');

        $create = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => $clientId,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 100,
            'vendor_cost' => 60,
            'initial_payment' => 25,
            'payment_method' => 'cash',
        ])->assertCreated();

        $operationId = $create->json('id');
        $this->assertSame($before + 6, JournalEntry::count());

        $accountant = User::where('role', 'accountant')->first();

        $this->actingAs($accountant)->postJson("/api/operations/{$operationId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame($before + 12, JournalEntry::count());
        $this->assertEquals(JournalEntry::sum('debit'), JournalEntry::sum('credit'));

        $clientBalance = app(AccountingService::class)->clientBalance($clientId);
        $this->assertEqualsWithDelta(0, $clientBalance, 0.01);

        $stmt = $this->actingAs($sales)->getJson("/api/clients/{$clientId}/statement")->assertOk();
        $this->assertEqualsWithDelta(0, $stmt->json('balance'), 0.01);
        $this->assertEqualsWithDelta(0, $stmt->json('paid'), 0.01);
    }

    public function test_double_cancel_returns_validation_error(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $operation = Operation::where('status', 'new')->where('initial_payment', 0)->first();

        $this->actingAs($accountant)->postJson("/api/operations/{$operation->id}/cancel")->assertOk();
        $this->actingAs($accountant)->postJson("/api/operations/{$operation->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['operation']);
    }

    public function test_list_endpoints_support_pagination(): void
    {
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->getJson('/api/clients?per_page=5&page=1')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_bootstrap_returns_metrics_not_full_journal(): void
    {
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($admin)->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonStructure(['metrics' => ['total_receipts', 'total_payments', 'journal_count', 'journal_balanced']])
            ->assertJsonMissing(['journal']);
    }

    public function test_unauthenticated_and_auditor_writes_blocked(): void
    {
        $this->postJson('/api/clients', ['name' => 'X', 'phone' => '1'])->assertUnauthorized();

        $auditor = User::where('role', 'auditor')->first();
        $this->actingAs($auditor)->postJson('/api/clients', ['name' => 'X', 'phone' => '90000099'])->assertForbidden();
        $this->actingAs($auditor)->patchJson('/api/services/1/toggle')->assertForbidden();
        $this->actingAs($auditor)->getJson('/api/users')->assertForbidden();
    }

    public function test_non_admin_bootstrap_hides_other_users(): void
    {
        $sales = User::where('role', 'sales')->first();
        $admin = User::where('role', 'admin')->first();

        $this->actingAs($sales)->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonCount(1, 'users');

        $this->actingAs($admin)->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonCount(4, 'users');
    }

    public function test_journal_remains_balanced_after_full_audit_flow(): void
    {
        $sales = User::where('role', 'sales')->first();
        $accountant = User::where('role', 'accountant')->first();

        $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Audit Flow Client',
            'phone' => '90000111',
        ])->assertCreated();

        $this->actingAs($sales)->postJson('/api/vendors', [
            'name' => 'Audit Flow Vendor',
            'category' => 'other',
        ])->assertCreated();

        $op = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 9,
            'service_id' => 1,
            'vendor_id' => 6,
            'client_price' => 80,
            'vendor_cost' => 50,
            'initial_payment' => 20,
        ])->assertCreated();

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => 9,
            'amount' => 10,
            'safe_id' => 1,
        ])->assertCreated();

        $this->actingAs($accountant)->postJson("/api/operations/{$op->json('id')}/cancel")->assertOk();

        $this->assertEquals(JournalEntry::sum('debit'), JournalEntry::sum('credit'));
    }
}
