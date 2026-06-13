<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Operation;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_operations_search_includes_notes(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $client = Client::first();
        $service = Service::first();
        $vendor = Vendor::first();

        Operation::create([
            'office_id' => $sales->office_id,
            'ref' => 'OP-NOTES-001',
            'client_id' => $client->id,
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'currency' => 'KWD',
            'client_price' => 100,
            'vendor_cost' => 80,
            'profit' => 20,
            'initial_payment' => 0,
            'payment_method' => 'cash',
            'notes' => 'VIP client special discount package',
            'status' => 'new',
            'created_by' => $sales->id,
            'op_date' => now()->toDateString(),
        ]);

        $this->actingAsWithOffice($sales)
            ->getJson('/api/operations?search=special+discount')
            ->assertOk()
            ->assertJsonPath('data.0.ref', 'OP-NOTES-001');
    }

    public function test_operations_date_filter_and_pagination(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($sales)
            ->getJson('/api/operations?from=2020-01-01&to=2020-01-31&per_page=25')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_default_pagination_is_25(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($sales)
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_profit_report_respects_date_range(): void
    {
        $accountant = User::where('role', 'accountant')->first();

        $this->actingAsWithOffice($accountant)
            ->getJson('/api/reports/profit?from=2099-01-01&to=2099-01-31')
            ->assertOk()
            ->assertJsonPath('rows.0.count', 0);
    }

    public function test_dashboard_respects_date_range(): void
    {
        $admin = User::where('role', 'admin')->first();

        $this->actingAsWithOffice($admin)
            ->getJson('/api/dashboard?from=2099-01-01&to=2099-01-31')
            ->assertOk()
            ->assertJsonPath('today_sales', 0)
            ->assertJsonPath('sales_label', 'مبيعات الفترة');
    }

    public function test_activity_logs_endpoint_supports_pagination(): void
    {
        $admin = User::where('role', 'admin')->first();

        $this->actingAsWithOffice($admin)
            ->getJson('/api/activity-logs?per_page=25')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_voucher_search_by_description(): void
    {
        $accountant = User::where('role', 'accountant')->first();
        $client = Client::first();

        $this->actingAsWithOffice($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => $client->id,
            'amount' => 10,
            'currency' => 'KWD',
            'method' => 'cash',
            'safe_id' => 1,
            'description' => 'UniqueVoucherMarker123',
        ])->assertCreated();

        $this->actingAsWithOffice($accountant)
            ->getJson('/api/vouchers?search=UniqueVoucherMarker123')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
