<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityDeliveryFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = (string) env('SEED_USER_PASSWORD', 'travel-erp-test-secret');
        $this->seed();
    }

    public function test_client_can_be_updated(): void
    {
        $sales = User::where('role', 'sales')->first();

        $this->actingAs($sales)->patchJson('/api/clients/1', [
            'name' => 'عميل محدّث',
            'notes' => 'ملاحظة محدثة',
        ])->assertOk()->assertJsonPath('name', 'عميل محدّث');
    }

    public function test_vendor_can_be_updated(): void
    {
        $accountant = User::where('role', 'accountant')->first();

        $this->actingAs($accountant)->patchJson('/api/vendors/1', [
            'contact' => 'قسم محدث',
        ])->assertOk()->assertJsonPath('contact', 'قسم محدث');
    }

    public function test_new_operation_can_be_updated_with_accounting_repost(): void
    {
        $sales = User::where('role', 'sales')->first();

        $create = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 120,
            'vendor_cost' => 80,
            'initial_payment' => 0,
        ])->assertCreated();

        $before = JournalEntry::count();

        $this->actingAs($sales)->patchJson('/api/operations/'.$create->json('id'), [
            'client_price' => 150,
            'vendor_cost' => 90,
            'notes' => 'تعديل سعر',
        ])->assertOk()
            ->assertJsonPath('client_price', 150)
            ->assertJsonPath('notes', 'تعديل سعر');

        $this->assertGreaterThan($before, JournalEntry::count());
        $this->assertTrue(app(AccountingService::class)->isJournalBalanced());
    }

    public function test_processing_operation_only_allows_notes_and_date(): void
    {
        $sales = User::where('role', 'sales')->first();

        $op = Operation::where('status', 'new')->first();
        $this->actingAs($sales)->patchJson("/api/operations/{$op->id}/status", ['status' => 'processing'])->assertOk();

        $this->actingAs($sales)->patchJson("/api/operations/{$op->id}", [
            'client_price' => 999,
        ])->assertStatus(422)->assertJsonValidationErrors(['client_price']);

        $this->actingAs($sales)->patchJson("/api/operations/{$op->id}", [
            'notes' => 'ملاحظة بعد النقل',
        ])->assertOk()->assertJsonPath('notes', 'ملاحظة بعد النقل');
    }

    public function test_voucher_void_reverses_journal_and_blocks_double_void(): void
    {
        $sales = User::where('role', 'sales')->first();
        $accountant = User::where('role', 'accountant')->first();

        $op = $this->actingAs($sales)->postJson('/api/operations', [
            'client_id' => 1,
            'service_id' => 1,
            'vendor_id' => 1,
            'client_price' => 200,
            'vendor_cost' => 100,
            'initial_payment' => 0,
        ])->assertCreated();

        $voucher = $this->actingAs($accountant)->postJson('/api/vouchers', [
            'type' => 'receipt',
            'party_type' => 'client',
            'party_id' => 1,
            'amount' => 50,
            'safe_id' => 1,
            'operation_id' => $op->json('id'),
        ])->assertCreated();

        $vid = $voucher->json('id');
        $before = JournalEntry::count();

        $this->actingAs($accountant)->postJson("/api/vouchers/{$vid}/void")
            ->assertOk()
            ->assertJsonPath('reversed', true);

        $this->assertSame($before + 2, JournalEntry::count());
        $this->assertNotNull(Voucher::find($vid)->voided_at);
        $this->assertTrue(app(AccountingService::class)->isJournalBalanced());

        $this->actingAs($accountant)->postJson("/api/vouchers/{$vid}/void")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['voucher']);
    }

    public function test_auditor_cannot_patch_or_void(): void
    {
        $auditor = User::where('role', 'auditor')->first();

        $this->actingAs($auditor)->patchJson('/api/clients/1', ['name' => 'x'])->assertForbidden();

        $voucher = Voucher::first();
        $this->actingAs($auditor)->postJson("/api/vouchers/{$voucher->id}/void")->assertForbidden();
    }
}
