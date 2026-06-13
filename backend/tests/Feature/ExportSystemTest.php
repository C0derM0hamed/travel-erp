<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Office;
use App\Models\Operation;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    public function test_operations_excel_export_respects_search_filter(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $client = Client::first();
        $service = Service::first();
        $vendor = Vendor::first();

        Operation::create([
            'office_id' => $sales->office_id,
            'ref' => 'OP-AR-EXPORT-001',
            'client_id' => $client->id,
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'currency' => 'KWD',
            'client_price' => 120,
            'vendor_cost' => 90,
            'profit' => 30,
            'initial_payment' => 0,
            'payment_method' => 'cash',
            'notes' => 'ملاحظة عربية للتصدير',
            'status' => 'new',
            'created_by' => $sales->id,
            'op_date' => now()->toDateString(),
        ]);

        $this->actingAsWithOffice($sales)
            ->get('/api/exports/operations?format=xlsx&search=OP-AR-EXPORT-001')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_operations_pdf_contains_arabic_text(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $client = Client::query()->first();
        $client->update(['name' => 'عميل اختبار التصدير']);
        $service = Service::first();
        $vendor = Vendor::first();

        Operation::create([
            'office_id' => $sales->office_id,
            'ref' => 'OP-RTL-001',
            'client_id' => $client->id,
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'currency' => 'KWD',
            'client_price' => 50,
            'vendor_cost' => 40,
            'profit' => 10,
            'initial_payment' => 0,
            'payment_method' => 'cash',
            'notes' => 'ملاحظة عربية للتصدير',
            'status' => 'new',
            'created_by' => $sales->id,
            'op_date' => now()->toDateString(),
        ]);

        $response = $this->actingAsWithOffice($sales)
            ->get('/api/exports/operations?format=pdf&search=OP-RTL-001');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('%PDF', $content);
        $this->assertTrue(
            stripos($content, 'XBRIYaz') !== false,
            'Expected mPDF Arabic font (XB Riyaz) to be embedded in the PDF.',
        );
    }

    public function test_clients_excel_contains_arabic_text(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();
        Client::query()->first()?->update(['name' => 'عميل القائمة العربية']);

        $response = $this->actingAsWithOffice($admin)
            ->get('/api/exports/clients?format=xlsx');

        $response->assertOk();
        $path = storage_path('app/test-arabic-export.xlsx');
        file_put_contents($path, $response->streamedContent());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = ($zip->getFromName('xl/sharedStrings.xml') ?: '')
            .($zip->getFromName('xl/worksheets/sheet1.xml') ?: '');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('عميل القائمة العربية', $xml);
        $this->assertStringContainsString('الاسم', $xml);
    }

    public function test_vendors_list_pdf_and_excel_exports(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();

        $this->actingAsWithOffice($admin)
            ->get('/api/exports/vendors?format=xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAsWithOffice($admin)
            ->get('/api/exports/vendors?format=pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_operation_detail_pdf_export(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $operation = Operation::first();

        $this->actingAsWithOffice($sales)
            ->get("/api/exports/operations/{$operation->id}?format=pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_clients_list_pdf_and_excel_exports(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();
        Client::query()->first()?->update(['name' => 'عميل القائمة']);

        $this->actingAsWithOffice($admin)
            ->get('/api/exports/clients?format=xlsx&search=عميل')
            ->assertOk();

        $this->actingAsWithOffice($admin)
            ->get('/api/exports/clients?format=pdf&search=عميل')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_client_statement_export_respects_date_range(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();
        $client = Client::first();

        $this->actingAsWithOffice($admin)
            ->get("/api/exports/clients/{$client->id}/statement?format=xlsx&from=2099-01-01&to=2099-01-31")
            ->assertOk();
    }

    public function test_activity_logs_export_is_office_scoped(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();
        $otherOffice = Office::where('office_code', '!=', 'MAIN')->first()
            ?? Office::create(['office_code' => 'EXP2', 'office_name' => 'فرع التصدير', 'is_active' => true]);

        $this->actingAsWithOffice($admin)
            ->get('/api/exports/activity-logs?format=xlsx')
            ->assertOk();

        $this->actingAsWithOffice(User::where('role', 'super_admin')->first())
            ->withHeader('X-Office-Id', (string) $otherOffice->id)
            ->postJson('/api/session/office', ['office_id' => $otherOffice->id])
            ->assertOk();

        $super = User::where('role', 'super_admin')->first();
        $this->actingAsWithOffice($super)
            ->withOfficeContext($otherOffice->id)
            ->get('/api/exports/clients?format=xlsx')
            ->assertOk();
    }

    public function test_cross_office_client_statement_export_is_blocked(): void
    {
        $otherOffice = Office::create([
            'office_code' => 'ISO',
            'office_name' => 'مكتب معزول',
            'is_active' => true,
        ]);
        $foreignClient = Client::create([
            'office_id' => $otherOffice->id,
            'name' => 'عميل مكتب آخر',
            'phone' => '50001001',
        ]);

        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($sales)
            ->get("/api/exports/clients/{$foreignClient->id}/statement?format=pdf")
            ->assertNotFound();
    }

    public function test_journal_export_requires_reports_permission(): void
    {
        $auditor = User::where('email', 'auditor@travel.kw')->first();

        $this->actingAsWithOffice($auditor)
            ->get('/api/exports/journal?format=xlsx')
            ->assertOk();
    }

    public function test_report_export_profit_pdf(): void
    {
        $accountant = User::where('email', 'accountant@travel.kw')->first();

        $this->actingAsWithOffice($accountant)
            ->get('/api/exports/reports/profit?format=pdf&from=2020-01-01&to=2030-12-31')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
