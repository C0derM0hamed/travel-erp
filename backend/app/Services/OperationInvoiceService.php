<?php

namespace App\Services;

use App\Models\Operation;
use App\Models\Office;
use App\Models\Voucher;
use App\Services\Exports\ExportContext;
use App\Services\Exports\ExportLabels;
use App\Services\Exports\PdfExportService;
use App\Support\OfficeContext;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class OperationInvoiceService
{
    public function __construct(
        private AccountingService $accounting,
        private PdfExportService $pdf,
        private ExportContext $exportContext,
        private OfficeContext $officeContext,
    ) {}

    public function signedUrl(Operation $operation, int $days = 30): string
    {
        return URL::temporarySignedRoute(
            'invoice.public',
            now()->addDays($days),
            ['operation' => $operation->id],
        );
    }

    /** @return array{phone:?string,whatsapp_url:?string,invoice_url:string,message:string,client_name:string,operation_ref:string} */
    public function sharePayload(Operation $operation): array
    {
        $operation->loadMissing('client');
        $client = $operation->client;
        $clientName = $client?->name ?? 'العميل';
        $invoiceUrl = $this->signedUrl($operation);
        $message = implode("\n", array_filter([
            "مرحباً {$clientName}،",
            '',
            'نرفق لكم فاتورة الخدمة:',
            "رقم العملية: {$operation->ref}",
            "رابط الفاتورة: {$invoiceUrl}",
            '',
            'شكراً لتعاملكم معنا.',
        ]));
        $phone = $this->normalizeWhatsAppPhone($client?->phone ?? $client?->alt_phone);

        return [
            'phone' => $phone,
            'whatsapp_url' => $phone ? 'https://wa.me/'.$phone.'?text='.rawurlencode($message) : null,
            'invoice_url' => $invoiceUrl,
            'message' => $message,
            'client_name' => $clientName,
            'operation_ref' => $operation->ref,
        ];
    }

    public function downloadPdf(Operation $operation, bool $inline = false): Response
    {
        return $this->withOperationOffice($operation, fn () => $this->pdf->download(
            'exports.invoice',
            $this->viewData($operation),
            'فاتورة_'.$operation->ref,
            $inline,
        ));
    }

    /** @return array<string, mixed> */
    public function viewData(Operation $operation): array
    {
        $operation->load(['client', 'service', 'vendor', 'vouchers']);
        $outstanding = $this->accounting->operationClientOutstanding($operation->id);
        $paid = max(0, (float) $operation->client_price - $outstanding);
        $receipts = $operation->vouchers
            ->filter(fn (Voucher $v) => $v->type === 'receipt' && $v->voided_at === null)
            ->map(fn (Voucher $v) => [
                'ref' => $v->ref,
                'date' => $v->voucher_date?->toDateString() ?? '',
                'amount' => ExportLabels::formatAmount((float) $v->amount),
                'method' => ExportLabels::method($v->method),
            ])->values()->all();

        return [
            'title' => 'فاتورة ضريبية',
            'subtitle' => 'Tax Invoice',
            'invoice' => [
                'ref' => $operation->ref,
                'date' => $operation->op_date?->toDateString() ?? '',
                'status' => ExportLabels::operationStatus($operation->status),
            ],
            'client' => [
                'name' => $operation->client?->name ?? '',
                'phone' => $operation->client?->phone ?? '',
                'civil_id' => $operation->client?->civil_id ?? '',
                'email' => $operation->client?->email ?? '',
            ],
            'line_items' => [[
                'description' => $operation->service?->name ?? 'خدمة',
                'details' => trim(($operation->vendor?->name ? 'المورد: '.$operation->vendor->name : '').($operation->notes ? ' — '.$operation->notes : '')),
                'amount' => (float) $operation->client_price,
            ]],
            'summary' => [
                ['label' => 'إجمالي الفاتورة', 'value' => ExportLabels::formatAmount((float) $operation->client_price)],
                ['label' => 'المدفوع', 'value' => ExportLabels::formatAmount($paid)],
                ['label' => 'المتبقي', 'value' => ExportLabels::formatAmount($outstanding)],
            ],
            'payment_method' => ExportLabels::method($operation->payment_method),
            'currency' => 'د.ك',
            'receipts' => $receipts,
            'notes' => $operation->notes,
        ];
    }

    private function withOperationOffice(Operation $operation, callable $callback): Response
    {
        $previous = $this->officeContext->id();
        $this->officeContext->setOfficeId((int) $operation->office_id);

        try {
            return $callback();
        } finally {
            $this->officeContext->setOfficeId($previous);
        }
    }

    public function brandingForOperation(Operation $operation): array
    {
        $office = Office::find($operation->office_id);
        if (! $office) {
            return $this->exportContext->branding();
        }

        return [
            'id' => $office->id,
            'office_code' => $office->office_code,
            'office_name' => $office->office_name,
            'logo_url' => $this->exportContext->logoAbsoluteUrl($office),
        ];
    }

    public function normalizeWhatsAppPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        $digits = ltrim($digits, '0');
        if (str_starts_with($digits, '965')) {
            return $digits;
        }

        return '965'.$digits;
    }
}
