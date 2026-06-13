<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Operation;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OperationInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    public function test_operation_invoice_pdf_export(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $operation = Operation::first();

        $this->actingAsWithOffice($sales)
            ->get("/api/exports/operations/{$operation->id}/invoice?format=pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_invoice_share_includes_whatsapp_url_and_signed_link(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $client = Client::first();
        $client->update(['phone' => '99123456']);
        $operation = Operation::where('client_id', $client->id)->first() ?? Operation::first();

        $response = $this->actingAsWithOffice($sales)
            ->getJson("/api/operations/{$operation->id}/invoice-share")
            ->assertOk()
            ->json();

        $this->assertSame($operation->ref, $response['operation_ref']);
        $this->assertSame($client->name, $response['client_name']);
        $this->assertStringContainsString('96599123456', $response['phone']);
        $this->assertStringContainsString('wa.me/96599123456', $response['whatsapp_url']);
        $this->assertStringContainsString($operation->ref, $response['message']);
        $this->assertStringContainsString('/invoice/', $response['invoice_url']);
    }

    public function test_public_signed_invoice_url_serves_pdf_without_auth(): void
    {
        $operation = Operation::first();
        $url = URL::temporarySignedRoute('invoice.public', now()->addHour(), ['operation' => $operation->id]);

        $this->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_public_invoice_url_rejects_invalid_signature(): void
    {
        $operation = Operation::first();

        $this->get("/invoice/{$operation->id}?signature=invalid")
            ->assertForbidden();
    }

    public function test_invoice_share_without_phone_returns_null_whatsapp_url(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $client = Client::first();
        $client->update(['phone' => '', 'alt_phone' => '']);
        $operation = Operation::where('client_id', $client->id)->first() ?? Operation::first();

        $response = $this->actingAsWithOffice($sales)
            ->getJson("/api/operations/{$operation->id}/invoice-share")
            ->assertOk()
            ->json();

        $this->assertNull($response['phone']);
        $this->assertNull($response['whatsapp_url']);
        $this->assertNotEmpty($response['invoice_url']);
    }

    public function test_whatsapp_phone_normalization(): void
    {
        $service = app(\App\Services\OperationInvoiceService::class);

        $this->assertSame('96599123456', $service->normalizeWhatsAppPhone('99123456'));
        $this->assertSame('96599123456', $service->normalizeWhatsAppPhone('+965 9912 3456'));
        $this->assertSame('96599123456', $service->normalizeWhatsAppPhone('0096599123456'));
    }
}
