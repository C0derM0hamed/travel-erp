<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_post_requests_with_same_idempotency_key_are_not_processed_twice(): void
    {
        $sales = User::where('role', 'sales')->first();
        $headers = ['Idempotency-Key' => 'client-create-001'];

        $payload = ['name' => 'Idempotent Client', 'phone' => '90007771'];

        $this->actingAs($sales)->postJson('/api/clients', $payload, $headers)->assertCreated();
        $this->actingAs($sales)->postJson('/api/clients', $payload, $headers)->assertCreated();

        $this->assertDatabaseCount('idempotency_keys', 1);
        $this->assertDatabaseHas('clients', ['phone' => '90007771']);
        $this->assertSame(1, Client::where('phone', '90007771')->count());
    }

    public function test_non_kwd_operation_and_voucher_currency_are_rejected(): void
    {
        $sales = User::where('role', 'sales')->first();
        $accountant = User::where('role', 'accountant')->first();

        $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'currency' => 'USD',
            'client_price' => 100,
            'vendor_cost' => 50,
        ])->assertStatus(422)->assertJsonValidationErrors(['currency']);

        $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => 1,
            'currency' => 'USD',
            'amount' => 10,
            'safe_id' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['currency']);
    }

    public function test_initial_payment_uses_safe_type_not_hardcoded_id(): void
    {
        $sales = User::where('role', 'sales')->first();
        Safe::whereKey(2)->update(['is_active' => false]);
        $newBank = Safe::create(['name' => 'بنك احتياطي', 'type' => 'bank', 'currency' => 'KWD', 'opening_balance' => 0]);
        ChartOfAccount::create(['code' => '1009', 'name' => 'بنك احتياطي', 'type' => 'asset', 'safe_id' => $newBank->id]);

        $response = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 120,
            'vendor_cost' => 60,
            'initial_payment' => 20,
            'payment_method' => 'bank',
        ])->assertCreated();

        $this->assertDatabaseHas('vouchers', [
            'operation_id' => $response->json('id'),
            'safe_id' => $newBank->id,
        ]);
    }

    public function test_fully_settled_new_operation_does_not_auto_complete_until_processing(): void
    {
        $sales = User::where('role', 'sales')->first();

        $response = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 50,
            'vendor_cost' => 0,
            'initial_payment' => 50,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame('new', Operation::find($response->json('id'))->status);
    }

    public function test_admin_can_manage_users_and_password_change_endpoint_works(): void
    {
        $admin = User::where('role', 'admin')->first();
        $password = (string) env('SEED_USER_PASSWORD', 'travel-erp-test-secret');

        $created = $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'New Accountant',
            'email' => 'new-accountant@example.test',
            'password' => 'SecurePass123',
            'role' => 'accountant',
        ])->assertCreated();

        $this->assertTrue((bool) $created->json('must_change_password'));

        $this->actingAs($admin)->patchJson('/api/users/'.$created->json('id'), [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);

        $this->actingAs($admin)->patchJson('/api/profile/password', [
            'current_password' => $password,
            'password' => 'NewSecurePass123',
            'password_confirmation' => 'NewSecurePass123',
        ])->assertOk();
    }

    public function test_sensitive_actions_are_written_to_activity_log(): void
    {
        $sales = User::where('role', 'sales')->first();

        $this->actingAs($sales)->postJson('/api/clients', [
            'name' => 'Logged Client',
            'phone' => '90007772',
        ])->assertCreated();

        $this->assertTrue(ActivityLog::where('action', 'client.created')->exists());
    }
}
