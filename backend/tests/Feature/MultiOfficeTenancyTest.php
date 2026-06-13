<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Office;
use App\Models\User;
use App\Services\OfficeProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiOfficeTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_user_cannot_access_other_office_client(): void
    {
        $officeB = app(OfficeProvisioningService::class)->createOffice([
            'office_code' => 'BR2',
            'office_name' => 'فرع 2',
        ]);

        $clientB = Client::withoutGlobalScopes()->create([
            'office_id' => $officeB->id,
            'name' => 'عميل فرع 2',
            'phone' => '99000001',
        ]);

        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($sales)
            ->getJson("/api/clients/{$clientB->id}/statement")
            ->assertNotFound();
    }

    public function test_inactive_office_blocks_login(): void
    {
        $office = Office::where('office_code', 'MAIN')->first();
        $office->update(['is_active' => false]);

        $password = env('SEED_USER_PASSWORD', 'travel-erp-test-secret');
        $this->postJson('/api/login', ['email' => 'sales@travel.kw', 'password' => $password])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_super_admin_can_manage_offices(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();

        $this->actingAsWithOffice($super)
            ->getJson('/api/offices')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->actingAsWithOffice($super)
            ->postJson('/api/offices', [
                'office_code' => 'JAH',
                'office_name' => 'فرع الجهراء',
            ])
            ->assertCreated()
            ->assertJsonPath('office_code', 'JAH');

        $this->assertDatabaseHas('offices', ['office_code' => 'JAH']);
        $this->assertDatabaseHas('reference_sequences', ['office_id' => Office::where('office_code', 'JAH')->value('id'), 'key' => 'operation']);
    }

    public function test_same_phone_allowed_in_different_offices(): void
    {
        $officeB = app(OfficeProvisioningService::class)->createOffice([
            'office_code' => 'BR3',
            'office_name' => 'فرع 3',
        ]);

        Client::withoutGlobalScopes()->create([
            'office_id' => $officeB->id,
            'name' => 'عميل مشترك',
            'phone' => '99001122',
        ]);

        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($sales)
            ->postJson('/api/clients', [
                'name' => 'عميل مكرر الهاتف',
                'phone' => '99001122',
            ])
            ->assertStatus(422);
    }

    public function test_operation_rejects_cross_office_client_reference(): void
    {
        $officeB = app(OfficeProvisioningService::class)->createOffice([
            'office_code' => 'BR4',
            'office_name' => 'فرع 4',
        ]);

        $clientB = Client::withoutGlobalScopes()->create([
            'office_id' => $officeB->id,
            'name' => 'عميل خارجي',
            'phone' => '99111111',
        ]);

        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => $clientB->id,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 80,
            ])
            ->assertStatus(422);
    }

    public function test_bootstrap_includes_office_context(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();

        $this->actingAsWithOffice($admin)
            ->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonStructure(['offices', 'current_office', 'user'])
            ->assertJsonPath('current_office.office_code', 'MAIN');
    }
}
