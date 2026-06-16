<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Office;
use App\Models\Operation;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationCreateValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_invalid_client_returns_arabic_message(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => 99999,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 80,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'العميل المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.');
    }

    public function test_invalid_vendor_returns_arabic_message(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => 1,
                'service_id' => 1,
                'vendor_id' => 99999,
                'client_price' => 100,
                'vendor_cost' => 80,
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['vendor_id' => ['المورد المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.']]);
    }

    public function test_missing_required_fields_return_arabic_messages(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();

        $response = $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [])
            ->assertUnprocessable()
            ->json();

        $this->assertStringContainsString('العميل', $response['message']);
        $this->assertArrayHasKey('client_id', $response['errors']);
        $this->assertArrayHasKey('vendor_id', $response['errors']);
        $this->assertArrayHasKey('service_id', $response['errors']);
        $this->assertArrayHasKey('client_price', $response['errors']);
        $this->assertArrayHasKey('vendor_cost', $response['errors']);
    }

    public function test_invalid_amounts_return_arabic_messages(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => 1,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 0,
                'vendor_cost' => 80,
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['client_price' => ['الحد الأدنى لسعر العميل 1.']]);

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => 1,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 150,
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['vendor_cost' => ['تكلفة المورد لا يمكن أن تتجاوز سعر العميل.']]);
    }

    public function test_over_payment_returns_arabic_message(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => 1,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 80,
                'initial_payment' => 150,
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['initial_payment' => ['الدفعة الأولى لا يمكن أن تتجاوز سعر العميل.']]);
    }

    public function test_hidden_client_returns_arabic_message(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $client = Client::find(1);
        $client->update(['is_hidden' => true]);

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => $client->id,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 80,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'العميل المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.');
    }

    public function test_cross_office_client_returns_arabic_message(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $otherOffice = Office::create([
            'office_code' => 'XOF',
            'office_name' => 'مكتب آخر',
            'is_active' => true,
        ]);
        $foreignClient = Client::withoutGlobalScopes()->create([
            'office_id' => $otherOffice->id,
            'name' => 'عميل خارجي',
            'phone' => '50009999',
            'is_hidden' => false,
        ]);

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => $foreignClient->id,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 80,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'العميل المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.');
    }

    public function test_auditor_cannot_create_operation(): void
    {
        $auditor = User::where('email', 'auditor@travel.kw')->first();

        $this->actingAsWithOffice($auditor)
            ->postJson('/api/operations', [
                'client_id' => 1,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 80,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');
    }

    public function test_inactive_service_returns_arabic_message(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        Service::find(1)->update(['active' => false]);

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => 1,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 80,
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['service_id' => ['الخدمة غير موجودة أو غير مفعّلة.']]);
    }

    public function test_successful_create_after_seed_fix(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        Client::whereKey(1)->update(['is_hidden' => false]);
        Service::whereKey(1)->update(['active' => true]);

        $this->actingAsWithOffice($sales)
            ->postJson('/api/operations', [
                'client_id' => 1,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 80,
                'initial_payment' => 0,
                'payment_method' => 'cash',
            ])
            ->assertCreated();
    }

    public function test_sales_user_create_via_middleware_office_context(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAs($sales)
            ->postJson('/api/operations', [
                'client_id' => 1,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 120,
                'vendor_cost' => 90,
            ])
            ->assertCreated();
    }

    public function test_admin_user_create_succeeds(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();

        $this->actingAs($admin)
            ->postJson('/api/operations', [
                'client_id' => 2,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 150,
                'vendor_cost' => 100,
            ])
            ->assertCreated();
    }

    public function test_super_admin_create_with_office_header(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();
        $officeId = Office::where('office_code', 'MAIN')->value('id');

        $this->actingAs($super)
            ->withHeader('X-Office-Id', (string) $officeId)
            ->postJson('/api/operations', [
                'client_id' => 3,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 200,
                'vendor_cost' => 150,
            ])
            ->assertCreated();
    }

    public function test_cross_office_client_id_rejected_for_office_user(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $otherOffice = Office::create([
            'office_code' => 'BR2',
            'office_name' => 'فرع ثانٍ',
            'is_active' => true,
        ]);
        $foreignClient = Client::withoutGlobalScopes()->create([
            'office_id' => $otherOffice->id,
            'name' => 'عميل الفرع',
            'phone' => '50008888',
            'is_hidden' => false,
        ]);

        $this->actingAs($sales)
            ->postJson('/api/operations', [
                'client_id' => $foreignClient->id,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 80,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'العميل المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.');
    }

    public function test_me_returns_current_office_id_from_session(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAs($sales)
            ->withSession(['current_office_id' => $sales->office_id])
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.current_office_id', $sales->office_id);
    }

    public function test_validation_errors_are_arabic_in_errors_object(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();

        $response = $this->actingAs($sales)
            ->postJson('/api/operations', [
                'client_id' => 99999,
                'service_id' => 1,
                'vendor_id' => 1,
                'client_price' => 100,
                'vendor_cost' => 80,
            ])
            ->assertUnprocessable()
            ->json();

        $this->assertStringNotContainsString('invalid', strtolower($response['message']));
        $this->assertStringContainsString('العميل', $response['message']);
        $this->assertSame('العميل المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.', $response['errors']['client_id'][0]);
    }
}
