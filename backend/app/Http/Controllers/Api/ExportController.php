<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Services\AccountingService;
use App\Services\Exports\ExcelExportService;
use App\Services\Exports\ExportLabels;
use App\Services\Exports\ExportQueryService;
use App\Services\Exports\ExportReportService;
use App\Services\Exports\PdfExportService;
use App\Support\OfficeContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends ApiController
{
    public function __construct(
        AccountingService $accounting,
        private ExportQueryService $queries,
        private ExportReportService $reports,
        private PdfExportService $pdf,
        private ExcelExportService $excel,
        private OfficeContext $officeContext,
    ) {
        parent::__construct($accounting);
    }

    public function operations(Request $request): Response
    {
        Gate::authorize('viewAny', Operation::class);
        $format = $this->validatedFormat($request);

        $headers = ['المرجع', 'التاريخ', 'العميل', 'الخدمة', 'المورد', 'سعر العميل', 'التكلفة', 'الربح', 'الحالة'];
        $rows = $this->lazyRows($this->queries->operationsQuery($request), function (Operation $operation) {
            return [
                $operation->ref,
                $operation->op_date?->toDateString() ?? '',
                $operation->client?->name ?? '',
                $operation->service?->name ?? '',
                $operation->vendor?->name ?? '',
                (float) $operation->client_price,
                (float) $operation->vendor_cost,
                (float) $operation->profit,
                ExportLabels::operationStatus($operation->status),
            ];
        });

        return $this->tableExport($format, 'قائمة العمليات', $headers, $rows, 'العمليات', $request->boolean('inline'));
    }

    public function operation(Request $request, Operation $operation): Response
    {
        Gate::authorize('view', $operation);
        $format = $this->validatedFormat($request, ['pdf']);
        $operation->load(['client', 'service', 'vendor', 'vouchers']);

        $journalRows = JournalEntry::with('account')->where('operation_id', $operation->id)->orderBy('id')->get()
            ->map(fn (JournalEntry $j) => [
                $j->account?->name ?? '',
                ExportLabels::formatAmount((float) $j->debit),
                ExportLabels::formatAmount((float) $j->credit),
                $j->description ?? '',
            ])->all();

        $voucherRows = $operation->vouchers->map(fn (Voucher $v) => [
            $v->ref,
            ExportLabels::voucherType($v->type),
            ExportLabels::formatAmount((float) $v->amount),
            ExportLabels::method($v->method),
            $v->voucher_date?->toDateString() ?? '',
        ])->all();

        return $this->pdf->download('exports.detail', [
            'title' => 'تفاصيل العملية - '.$operation->ref,
            'fields' => ExportLabels::operationDetailFields($operation),
            'sections' => [
                [
                    'title' => 'القيود المحاسبية',
                    'headers' => ['الحساب', 'مدين', 'دائن', 'البيان'],
                    'rows' => $journalRows,
                ],
                [
                    'title' => 'السندات المرتبطة',
                    'headers' => ['الرقم', 'النوع', 'المبلغ', 'الطريقة', 'التاريخ'],
                    'rows' => $voucherRows,
                ],
            ],
            'rowCount' => count($journalRows) + count($voucherRows),
        ], 'عملية_'.$operation->ref, $request->boolean('inline'));
    }

    public function operationInvoice(Request $request, Operation $operation, \App\Services\OperationInvoiceService $invoices): Response
    {
        Gate::authorize('view', $operation);

        return $invoices->downloadPdf($operation, $request->boolean('inline'));
    }

    public function clients(Request $request): Response
    {
        Gate::authorize('viewAny', Client::class);
        $format = $this->validatedFormat($request);

        $headers = ['#', 'الاسم', 'الهاتف', 'الرقم المدني', 'الإيميل', 'الجنسية', 'الرصيد'];
        $rows = $this->lazyRows($this->queries->clientsQuery($request), function (Client $client) {
            return [
                $client->id,
                $client->name,
                $client->phone ?? '',
                $client->civil_id ?? '',
                $client->email ?? '',
                $client->nationality ?? '',
                $this->accounting->clientBalance($client->id),
            ];
        });

        return $this->tableExport($format, 'قائمة العملاء', $headers, $rows, 'العملاء', $request->boolean('inline'));
    }

    public function clientStatement(Request $request, Client $client): Response
    {
        Gate::authorize('view', $client);
        $format = $this->validatedFormat($request);

        $running = 0.0;
        $rows = [];
        foreach ($this->queries->clientStatementQuery($client, $request)->lazy(500) as $journal) {
            $running += (float) $journal->debit - (float) $journal->credit;
            $rows[] = [
                $journal->entry_date?->toDateString() ?? '',
                $journal->ref,
                $journal->description ?? '',
                (float) $journal->debit,
                (float) $journal->credit,
                round($running, 3),
            ];
        }

        $summary = [
            ['label' => 'إجمالي المشتريات', 'value' => ExportLabels::formatAmount($this->clientPurchases($client, $request))],
            ['label' => 'المدفوع', 'value' => ExportLabels::formatAmount($this->accounting->clientReceiptsTotal($client->id))],
            ['label' => 'الرصيد المتبقي', 'value' => ExportLabels::formatAmount($this->accounting->clientBalance($client->id))],
        ];

        $branding = $this->brandingForOffice($client->office_id);

        return $this->withOfficeContext($client->office_id, fn () => $this->statementExport(
            $format,
            'كشف حساب - '.$client->name,
            ['التاريخ', 'المرجع', 'البيان', 'مدين', 'دائن', 'الرصيد'],
            $rows,
            'كشف_حساب_'.$client->name,
            $summary,
            $request->boolean('inline'),
            $branding
        ));
    }

    public function vendors(Request $request): Response
    {
        Gate::authorize('viewAny', Vendor::class);
        $format = $this->validatedFormat($request);

        $headers = ['#', 'الاسم', 'التصنيف', 'الهاتف', 'جهة الاتصال', 'الرصيد'];
        $rows = $this->lazyRows($this->queries->vendorsQuery($request), function (Vendor $vendor) {
            return [
                $vendor->id,
                $vendor->name,
                ExportLabels::vendorCategory($vendor->category),
                $vendor->phone ?? '',
                $vendor->contact ?? '',
                $this->accounting->vendorBalance($vendor->id),
            ];
        });

        return $this->tableExport($format, 'قائمة الموردين', $headers, $rows, 'الموردون', $request->boolean('inline'));
    }

    public function vendorStatement(Request $request, Vendor $vendor): Response
    {
        Gate::authorize('view', $vendor);
        $format = $this->validatedFormat($request);

        $rows = [];
        $totalOwed = 0.0;
        foreach ($this->queries->vendorStatementQuery($vendor, $request)->lazy(500) as $journal) {
            $totalOwed += (float) $journal->credit;
            $rows[] = [
                $journal->entry_date?->toDateString() ?? '',
                $journal->ref,
                $journal->description ?? '',
                (float) $journal->debit,
                (float) $journal->credit,
            ];
        }

        $summary = [
            ['label' => 'إجمالي المستحقات', 'value' => ExportLabels::formatAmount($totalOwed)],
            ['label' => 'المدفوع', 'value' => ExportLabels::formatAmount($this->accounting->vendorPaymentsTotal($vendor->id))],
            ['label' => 'الرصيد الحالي', 'value' => ExportLabels::formatAmount($this->accounting->vendorBalance($vendor->id))],
        ];

        $branding = $this->brandingForOffice($vendor->office_id);

        return $this->withOfficeContext($vendor->office_id, fn () => $this->statementExport(
            $format,
            'كشف حساب مورد - '.$vendor->name,
            ['التاريخ', 'المرجع', 'البيان', 'مدين', 'دائن'],
            $rows,
            'كشف_مورد_'.$vendor->name,
            $summary,
            $request->boolean('inline'),
            $branding
        ));
    }

    public function vouchers(Request $request): Response
    {
        Gate::authorize('viewAny', Voucher::class);
        $format = $this->validatedFormat($request);

        $title = $request->type === 'payment' ? 'سندات الصرف' : ($request->type === 'receipt' ? 'سندات القبض' : 'السندات المالية');
        $headers = ['الرقم', 'النوع', 'التاريخ', 'الطرف', 'المبلغ', 'الطريقة', 'الصندوق', 'الحالة', 'البيان'];
        $rows = $this->lazyRows($this->queries->vouchersQuery($request), function (Voucher $voucher) {
            $reversed = $voucher->voided_at !== null;

            return [
                $voucher->ref,
                ExportLabels::voucherType($voucher->type),
                $voucher->voucher_date?->toDateString() ?? '',
                ExportLabels::partyName($voucher),
                (float) $voucher->amount,
                ExportLabels::method($voucher->method),
                ExportLabels::safeName($voucher->safe_id),
                $reversed ? 'ملغى' : 'فعّال',
                $voucher->description ?? '',
            ];
        });

        return $this->tableExport($format, $title, $headers, $rows, 'السندات', $request->boolean('inline'));
    }

    public function voucher(Request $request, Voucher $voucher): Response
    {
        Gate::authorize('view', $voucher);
        $format = $this->validatedFormat($request, ['pdf']);
        $voucher->load(['safe', 'operation']);

        $fields = [
            ['label' => 'رقم السند', 'value' => $voucher->ref],
            ['label' => 'النوع', 'value' => ExportLabels::voucherType($voucher->type)],
            ['label' => 'التاريخ', 'value' => $voucher->voucher_date?->toDateString() ?? ''],
            ['label' => 'الطرف', 'value' => ExportLabels::partyName($voucher)],
            ['label' => 'المبلغ', 'value' => ExportLabels::formatAmount((float) $voucher->amount)],
            ['label' => 'الطريقة', 'value' => ExportLabels::method($voucher->method)],
            ['label' => 'الصندوق', 'value' => $voucher->safe?->name ?? ExportLabels::safeName($voucher->safe_id)],
            ['label' => 'العملية', 'value' => $voucher->operation?->ref ?? '—'],
            ['label' => 'البيان', 'value' => $voucher->description ?? ''],
            ['label' => 'الحالة', 'value' => $voucher->voided_at ? 'ملغى' : 'فعّال'],
        ];

        $branding = $this->brandingForOffice($voucher->office_id);

        return $this->withOfficeContext($voucher->office_id, fn () => $this->pdf->download('exports.detail', [
            'title' => 'سند '.$voucher->ref,
            'fields' => $fields,
            'branding' => $branding,
        ], 'سند_'.$voucher->ref, $request->boolean('inline')));
    }

    private function withOfficeContext(?int $officeId, callable $callback): Response
    {
        if (! $officeId) return $callback();
        $previous = $this->officeContext->id();
        $this->officeContext->setOfficeId($officeId);
        try { return $callback(); } finally { $this->officeContext->setOfficeId($previous); }
    }

    private function brandingForOffice(?int $officeId): array
    {
        $office = $officeId ? \App\Models\Office::find($officeId) : null;
        if (! $office) return app(\App\Services\Exports\ExportContext::class)->branding();
        
        return [
            'id' => $office->id,
            'office_code' => $office->office_code,
            'office_name' => $office->office_name,
            'logo_url' => app(\App\Services\Exports\ExportContext::class)->logoAbsoluteUrl($office),
        ];
    }

    public function journal(Request $request): Response
    {
        Gate::authorize('viewReports');
        $format = $this->validatedFormat($request);

        $headers = ['التاريخ', 'المرجع', 'الحساب', 'مدين', 'دائن', 'البيان'];
        $rows = $this->lazyRows($this->queries->journalQuery($request), function (JournalEntry $journal) {
            return [
                $journal->entry_date?->toDateString() ?? '',
                $journal->ref,
                $journal->account?->name ?? '',
                (float) $journal->debit,
                (float) $journal->credit,
                $journal->description ?? '',
            ];
        });

        return $this->tableExport($format, 'دفتر الأستاذ', $headers, $rows, 'دفتر_الأستاذ', $request->boolean('inline'));
    }

    public function report(Request $request, string $type): Response
    {
        Gate::authorize('viewReports');
        $format = $this->validatedFormat($request);

        $report = $this->reports->data($type, $request);

        return $this->tableExport(
            $format,
            $report['title'],
            $report['headers'],
            $report['rows'],
            $report['title'],
            $request->boolean('inline'),
        );
    }

    public function activityLogs(Request $request): Response
    {
        Gate::authorize('viewReports');
        $format = $this->validatedFormat($request);

        $headers = ['التاريخ', 'الإجراء', 'المستخدم', 'المكتب', 'مرجع العملية', 'التفاصيل', 'IP'];
        $rows = $this->lazyRows($this->queries->activityLogsQuery($request), function ($log) {
            $payload = is_array($log->payload) ? $log->payload : [];

            return [
                $log->created_at?->timezone('Asia/Kuwait')->format('Y-m-d H:i') ?? '',
                ExportLabels::activityAction($log->action),
                $log->user?->name ?? ($payload['user_name'] ?? ''),
                $log->office?->office_name ?? ($payload['office_name'] ?? ''),
                $payload['ref'] ?? '—',
                ExportLabels::activityDetails($log->action, is_array($log->payload) ? $log->payload : null),
                $log->ip ?? '',
            ];
        });

        return $this->tableExport($format, 'سجل النشاط', $headers, $rows, 'سجل_النشاط', $request->boolean('inline'));
    }

    /** @param list<string> $allowed */
    private function validatedFormat(Request $request, array $allowed = ['pdf', 'xlsx']): string
    {
        $request->validate([
            'format' => ['required', Rule::in($allowed)],
        ]);

        return $request->string('format')->toString();
    }

    /** @param callable(mixed): array<int, mixed> $mapper @return \Generator<int, array<int, mixed>> */
    private function lazyRows($query, callable $mapper): \Generator
    {
        foreach ($query->clone()->lazy(500) as $model) {
            yield $mapper($model);
        }
    }

    /** @param list<string> $headers @param iterable<int, array<int, mixed>> $rows */
    private function tableExport(string $format, string $title, array $headers, iterable $rows, string $filename, bool $inline): Response
    {
        if ($format === 'xlsx') {
            return $this->excel->download($headers, $rows, $filename);
        }

        $materialized = $rows instanceof \Generator ? iterator_to_array($rows, false) : (is_array($rows) ? $rows : iterator_to_array($rows, false));

        return $this->pdf->table($title, $headers, $materialized, $filename, $inline);
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows @param list<array{label:string,value:string}> $summary */
    private function statementExport(string $format, string $title, array $headers, array $rows, string $filename, array $summary, bool $inline, array $branding = []): Response
    {
        if ($format === 'xlsx') {
            return $this->excel->download($headers, $rows, $filename);
        }

        $payload = [
            'title' => $title,
            'summary' => $summary,
            'sections' => [[
                'title' => 'حركات الحساب',
                'headers' => $headers,
                'rows' => $rows,
            ]],
            'rowCount' => count($rows),
        ];

        if (!empty($branding)) {
            $payload['branding'] = $branding;
        }

        return $this->pdf->download('exports.detail', $payload, $filename, $inline);
    }

    private function clientPurchases(Client $client, Request $request): float
    {
        $opsQuery = Operation::where('client_id', $client->id)->where('status', '!=', 'cancelled');
        if ($request->filled('from')) {
            $opsQuery->whereDate('op_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $opsQuery->whereDate('op_date', '<=', $request->to);
        }

        return (float) $opsQuery->sum('client_price');
    }
}
