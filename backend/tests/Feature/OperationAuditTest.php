<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Operation;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_operation_create_logs_audit_entry(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $client = Client::withoutGlobalScopes()->where('office_id', $sales->office_id)->first();
        $service = Service::first();
        $vendor = Vendor::withoutGlobalScopes()->where('office_id', $sales->office_id)->first();

        $this->withOfficeContext($sales->office_id);

        $operation = app(OperationService::class)->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'client_price' => 150,
            'vendor_cost' => 100,
            'initial_payment' => 0,
            'payment_method' => 'cash',
            'notes' => 'اختبار التدقيق',
        ], $sales->id);

        $log = ActivityLog::where('action', 'operation.created')->where('payload->ref', $operation->ref)->first();

        $this->assertNotNull($log);
        $this->assertSame($sales->id, $log->user_id);
        $this->assertSame($sales->office_id, $log->office_id);
        $this->assertSame($sales->name, $log->payload['user_name'] ?? null);
        $this->assertNotEmpty($log->payload['office_name'] ?? null);
    }

    public function test_operation_update_hide_and_restore_are_audited(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $operation = Operation::visible()->where('status', 'new')->first();

        $this->actingAsWithOffice($sales)
            ->patchJson("/api/operations/{$operation->id}", ['notes' => 'ملاحظة محدثة'])
            ->assertOk();

        $this->assertTrue(
            ActivityLog::where('action', 'operation.updated')
                ->where('subject_id', $operation->id)
                ->where('payload->ref', $operation->ref)
                ->exists()
        );

        $this->actingAsWithOffice($sales)
            ->postJson("/api/operations/{$operation->id}/hide")
            ->assertOk();

        $this->assertTrue(
            ActivityLog::where('action', 'operation.hidden')
                ->where('subject_id', $operation->id)
                ->where('payload->ref', $operation->ref)
                ->exists()
        );

        $this->actingAsWithOffice($sales)
            ->postJson("/api/operations/{$operation->id}/restore")
            ->assertOk();

        $this->assertTrue(
            ActivityLog::where('action', 'operation.restored')
                ->where('subject_id', $operation->id)
                ->where('payload->ref', $operation->ref)
                ->exists()
        );
    }

    public function test_activity_log_api_returns_operation_audit_fields(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();
        $operation = Operation::first();

        $this->actingAsWithOffice($admin)
            ->postJson("/api/operations/{$operation->id}/hide")
            ->assertOk();

        $response = $this->actingAsWithOffice($admin)
            ->getJson('/api/activity-logs?action=operation.hidden')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('operation.hidden', $response['action']);
        $this->assertSame('تم إخفاء عملية', $response['action_label']);
        $this->assertSame($operation->ref, $response['operation_ref']);
        $this->assertNotEmpty($response['user_name']);
        $this->assertNotEmpty($response['office_name']);
        $this->assertNotEmpty($response['created_at']);
    }

    public function test_activity_log_operations_filter_returns_all_operation_actions(): void
    {
        $admin = User::where('email', 'admin@travel.kw')->first();
        $operation = Operation::first();

        $this->actingAsWithOffice($admin)
            ->postJson("/api/operations/{$operation->id}/hide")
            ->assertOk();

        $response = $this->actingAsWithOffice($admin)
            ->getJson('/api/activity-logs?action=operations&per_page=100')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($response);
        foreach ($response as $row) {
            $this->assertStringStartsWith('operation.', $row['action']);
        }
    }
}
