<?php

namespace Tests\Feature;

use App\Support\ArabicMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArabicMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_activity_actions_are_arabic(): void
    {
        $this->assertSame('تم إنشاء صندوق', ArabicMessages::activityAction('safe.created'));
        $this->assertSame('تم تعديل صندوق', ArabicMessages::activityAction('safe.updated'));
        $this->assertSame('تم إنشاء عميل', ArabicMessages::activityAction('client.created'));
        $this->assertSame('تم تعديل عملية', ArabicMessages::activityAction('operation.updated'));
        $this->assertSame('تم إلغاء سند', ArabicMessages::activityAction('voucher.voided'));
    }

    public function test_activity_details_format_arabic_summary(): void
    {
        $details = ArabicMessages::activityDetails('operation.updated', [
            'ref' => 'OP-001',
            'name' => 'محمد',
            'status' => 'processing',
        ]);

        $this->assertStringContainsString('المرجع: OP-001', $details);
        $this->assertStringContainsString('الاسم: محمد', $details);
        $this->assertStringContainsString('الحالة: قيد التنفيذ', $details);
    }

    public function test_api_message_translation(): void
    {
        $this->assertSame(
            'العميل المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.',
            ArabicMessages::translateApiMessage('The selected client id is invalid.')
        );
        $this->assertSame(
            'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
            ArabicMessages::translateApiMessage('Forbidden')
        );
    }

    public function test_not_found_is_arabic(): void
    {
        $sales = \App\Models\User::where('email', 'sales@travel.kw')->first();

        $this->actingAs($sales)
            ->getJson('/api/operations/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'العنصر المطلوب غير موجود.');
    }
}
