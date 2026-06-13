<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Operation;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HideRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_client_can_be_hidden_and_restored(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();
        $client = Client::first();

        $this->actingAsWithOffice($admin)
            ->postJson("/api/clients/{$client->id}/hide")
            ->assertOk()
            ->assertJsonPath('is_hidden', true);

        $this->actingAsWithOffice($admin)
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonMissing(['id' => $client->id]);

        $this->actingAsWithOffice($admin)
            ->getJson('/api/clients?hidden=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $client->id]);

        $this->actingAsWithOffice($admin)
            ->postJson("/api/clients/{$client->id}/restore")
            ->assertOk()
            ->assertJsonPath('is_hidden', false);

        $this->actingAsWithOffice($admin)
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonFragment(['id' => $client->id]);
    }

    public function test_operation_can_be_hidden_and_restored(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $operation = Operation::first();

        $this->actingAsWithOffice($sales)
            ->postJson("/api/operations/{$operation->id}/hide")
            ->assertOk()
            ->assertJsonPath('is_hidden', true);

        $this->actingAsWithOffice($sales)
            ->getJson('/api/operations')
            ->assertOk()
            ->assertJsonMissing(['id' => $operation->id]);

        $this->actingAsWithOffice($sales)
            ->getJson('/api/operations?hidden=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $operation->id]);

        $this->actingAsWithOffice($sales)
            ->postJson("/api/operations/{$operation->id}/restore")
            ->assertOk()
            ->assertJsonPath('is_hidden', false);
    }

    public function test_hidden_client_excluded_from_dashboard_and_exports(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();
        $client = Client::first();

        $this->actingAsWithOffice($admin)->postJson("/api/clients/{$client->id}/hide")->assertOk();

        $dashboard = $this->actingAsWithOffice($admin)->getJson('/api/dashboard')->assertOk()->json();
        $debtorIds = collect($dashboard['top_debtors'] ?? [])->pluck('id');
        $this->assertFalse($debtorIds->contains($client->id));

        $this->actingAsWithOffice($admin)
            ->getJson('/api/exports/clients?format=xlsx')
            ->assertOk();
        $exportIds = Client::visible()->pluck('id');
        $this->assertFalse($exportIds->contains($client->id));
    }

    public function test_hidden_operation_excluded_from_reports_and_new_operation_client_validation(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $client = Client::first();
        $service = Service::first();
        $vendor = Vendor::first();

        $client->update(['is_hidden' => true]);

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => $client->id,
                'service_id' => $service->id,
                'vendor_id' => $vendor->id,
                'client_price' => 100,
                'vendor_cost' => 80,
                'initial_payment' => 0,
                'payment_method' => 'cash',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);

        $operation = Operation::where('is_hidden', false)->first();
        $this->actingAsWithOffice($sales)->postJson("/api/operations/{$operation->id}/hide")->assertOk();

        $report = $this->actingAsWithOffice($sales)
            ->getJson('/api/reports/operations')
            ->assertOk()
            ->json();

        $reportIds = collect($report['rows'] ?? [])->pluck('id');
        $this->assertFalse($reportIds->contains($operation->id));
    }

    public function test_hidden_records_respect_office_isolation(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();
        $otherOfficeClient = Client::withoutGlobalScopes()
            ->where('office_id', '!=', $admin->office_id)
            ->first();

        if (! $otherOfficeClient) {
            $this->markTestSkipped('No client in another office');
        }

        $this->actingAsWithOffice($admin)
            ->postJson("/api/clients/{$otherOfficeClient->id}/hide")
            ->assertNotFound();
    }

    public function test_hiding_preserves_accounting_data(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();
        $client = Client::whereHas('operations')->first() ?? Client::first();
        $balanceBefore = app(\App\Services\AccountingService::class)->clientBalance($client->id);
        $opsCount = Operation::where('client_id', $client->id)->count();

        $this->actingAsWithOffice($admin)->postJson("/api/clients/{$client->id}/hide")->assertOk();

        $balanceAfter = app(\App\Services\AccountingService::class)->clientBalance($client->id);
        $this->assertEqualsWithDelta($balanceBefore, $balanceAfter, 0.001);
        $this->assertSame($opsCount, Operation::where('client_id', $client->id)->count());

        $this->actingAsWithOffice($admin)
            ->getJson("/api/clients/{$client->id}/statement")
            ->assertOk();
    }
}
