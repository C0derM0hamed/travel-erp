<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Office;
use App\Models\Operation;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end multi-office QA flow (API-level, mirrors real customer journey).
 */
class MultiOfficeE2ETest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->password = env('SEED_USER_PASSWORD', 'travel-erp-test-secret');
    }

    public function test_full_multi_office_customer_journey(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();

        // 1. Super admin creates office A
        $officeA = $this->actingAsWithOffice($super)
            ->postJson('/api/offices', [
                'office_code' => 'QA-A',
                'office_name' => 'فرع QA A',
            ])
            ->assertCreated()
            ->json();

        $officeAId = $officeA['id'];

        // 2. Create users for office A (sales + accountant for full workflow)
        $this->actingAsWithOffice($super)
            ->postJson('/api/users', [
                'name' => 'موظف مبيعات A',
                'email' => 'qa-a-sales@travel.kw',
                'password' => $this->password,
                'role' => 'sales',
                'office_id' => $officeAId,
            ])
            ->assertCreated();

        $this->actingAsWithOffice($super)
            ->postJson('/api/users', [
                'name' => 'محاسب A',
                'email' => 'qa-a-acct@travel.kw',
                'password' => $this->password,
                'role' => 'accountant',
                'office_id' => $officeAId,
            ])
            ->assertCreated();

        // 3. Login as office A sales user
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => 'qa-a-sales@travel.kw', 'password' => $this->password])
            ->assertOk()
            ->assertJsonPath('user.office_id', $officeAId);

        $userASales = User::where('email', 'qa-a-sales@travel.kw')->first();
        $userAAccountant = User::where('email', 'qa-a-acct@travel.kw')->first();
        $this->withOfficeContext($officeAId);

        // 4. Create records in office A
        $clientA = $this->actingAs($userASales)
            ->postJson('/api/clients', ['name' => 'عميل QA A', 'phone' => '88001001'])
            ->assertCreated()
            ->json();

        $vendorA = $this->actingAs($userASales)
            ->postJson('/api/vendors', ['name' => 'مورد QA A', 'category' => 'airline'])
            ->assertCreated()
            ->json();

        $operationA = $this->actingAs($userASales)
            ->postJson('/api/operations', [
                'client_id' => $clientA['id'],
                'service_id' => 1,
                'vendor_id' => $vendorA['id'],
                'client_price' => 200,
                'vendor_cost' => 150,
                'initial_payment' => 100,
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('OP-001', $operationA['ref']);

        $safeAId = $this->actingAs($userAAccountant)
            ->getJson('/api/safes')
            ->assertOk()
            ->json('data.0.id');

        $voucherA = $this->actingAs($userAAccountant)
            ->postJson('/api/vouchers', [
                'type' => 'receipt',
                'party_type' => 'client',
                'party_id' => $clientA['id'],
                'amount' => 50,
                'safe_id' => $safeAId,
                'operation_id' => $operationA['id'],
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('RV-002', $voucherA['ref']);

        $dashboardA = $this->actingAs($userASales)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->json();

        $this->assertGreaterThan(0, $dashboardA['today_sales']);

        // 5. Create office B and user B
        $this->actingAsWithOffice($super);
        $officeB = $this->actingAs($super)
            ->postJson('/api/offices', [
                'office_code' => 'QA-B',
                'office_name' => 'فرع QA B',
            ])
            ->assertCreated()
            ->json();

        $officeBId = $officeB['id'];

        $this->actingAs($super)
            ->postJson('/api/users', [
                'name' => 'موظف فرع B',
                'email' => 'qa-b@travel.kw',
                'password' => $this->password,
                'role' => 'sales',
                'office_id' => $officeBId,
            ])
            ->assertCreated();

        $userB = User::where('email', 'qa-b@travel.kw')->first();

        // 6. Office B cannot see office A data
        $this->withOfficeContext($officeBId);
        $this->actingAs($userB)
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($userB)
            ->getJson('/api/operations')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($userB)
            ->getJson("/api/clients/{$clientA['id']}/statement")
            ->assertNotFound();

        $this->actingAs($userB)
            ->getJson("/api/operations/{$operationA['id']}")
            ->assertNotFound();

        $this->actingAs($userB)
            ->getJson("/api/vouchers/{$voucherA['id']}")
            ->assertNotFound();

        // Cross-office operation create
        $this->actingAs($userB)
            ->postJson('/api/operations', [
                'client_id' => $clientA['id'],
                'service_id' => 1,
                'vendor_id' => $vendorA['id'],
                'client_price' => 100,
                'vendor_cost' => 80,
            ])
            ->assertStatus(422);

        // Search isolation
        $this->actingAs($userB)
            ->getJson('/api/operations?search=OP-001')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Reports isolation
        $this->actingAs($userB)
            ->getJson('/api/reports/operations')
            ->assertOk()
            ->assertJsonPath('totals.revenue', 0);

        $this->actingAs($userB)
            ->getJson('/api/reports/clients-debt')
            ->assertOk()
            ->assertJsonPath('totalDebt', 0);

        // Dashboard isolation for B
        $dashboardB = $this->actingAs($userB)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->json();

        $this->assertSame(0.0, (float) $dashboardB['today_sales']);

        // Export-style full list isolation
        $allClientsB = $this->actingAs($userB)
            ->getJson('/api/clients?per_page=500')
            ->assertOk()
            ->json('data');

        $this->assertEmpty($allClientsB);

        // 7. Reference numbers isolated — create op in B gets OP-001 again
        $clientB = $this->actingAs($userB)
            ->postJson('/api/clients', ['name' => 'عميل QA B', 'phone' => '88002002'])
            ->assertCreated()
            ->json();

        $vendorB = $this->actingAs($userB)
            ->postJson('/api/vendors', ['name' => 'مورد QA B', 'category' => 'hotel'])
            ->assertCreated()
            ->json();

        $operationB = $this->actingAs($userB)
            ->postJson('/api/operations', [
                'client_id' => $clientB['id'],
                'service_id' => 1,
                'vendor_id' => $vendorB['id'],
                'client_price' => 300,
                'vendor_cost' => 200,
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('OP-001', $operationB['ref']);
        $this->assertNotSame($operationA['id'], $operationB['id']);

        // 8. Inactive office blocks login
        Office::whereKey($officeBId)->update(['is_active' => false]);
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => 'qa-b@travel.kw', 'password' => $this->password])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // 9. Super admin can switch offices and see scoped data
        $this->postJson('/api/login', ['email' => 'super@travel.kw', 'password' => $this->password])
            ->assertOk();

        $this->actingAs($super)
            ->postJson('/api/session/office', ['office_id' => $officeAId])
            ->assertOk()
            ->assertJsonPath('office.id', $officeAId);

        $this->withOfficeContext($officeAId);
        $responseA = $this->actingAs($super)->getJson('/api/clients?search=QA A');
        $responseA->assertOk();
        $clientsInA = $responseA->json('data');
        $this->assertGreaterThanOrEqual(1, count($clientsInA));

        $this->actingAs($super)
            ->postJson('/api/session/office', ['office_id' => $officeBId])
            ->assertOk();

        $this->withOfficeContext($officeBId);
        $responseB = $this->actingAs($super)->getJson('/api/clients');
        $responseB->assertOk();
        $clientsInB = $responseB->json('data');
        $this->assertTrue(collect($clientsInB)->every(fn ($c) => ($c['phone'] ?? '') !== '88001001'));
    }

    public function test_inactive_office_blocks_existing_session(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        Office::where('office_code', 'MAIN')->update(['is_active' => false]);

        $this->actingAsWithOffice($sales)
            ->getJson('/api/bootstrap')
            ->assertForbidden();
    }
}
