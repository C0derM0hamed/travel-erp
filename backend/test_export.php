<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Office;
use App\Models\User;
use App\Models\Operation;
use App\Support\OfficeContext;
use App\Services\Exports\PdfExportService;

// Create a mock office
$office = Office::firstOrCreate(
    ['office_code' => 'ABUHANI'],
    ['office_name' => 'Office Abu Hani', 'logo' => 'abu_hani.png', 'is_active' => true]
);

// Create an operation for this office
$operation = Operation::withoutGlobalScopes()->first();
if ($operation) {
    $operation->office_id = $office->id;
    $operation->saveQuietly();
}

// Instantiate the export controller / service
$context = app(OfficeContext::class);
$context->setOfficeId($office->id);

$exportContext = app(App\Services\Exports\ExportContext::class);
$branding = $exportContext->branding();

echo "BRANDING OFFICE: " . $branding['office_name'] . "\n";

$invoiceService = app(App\Services\OperationInvoiceService::class);
$data = $invoiceService->viewData($operation);
$payload = array_merge($data, [
    'branding' => $exportContext->branding(),
    'generatedAt' => now()->timezone('Asia/Kuwait')->format('Y-m-d H:i'),
]);

$html = View::make('exports.invoice', $payload)->render();
if (strpos($html, 'Office Abu Hani') !== false) {
    echo "HTML contains Abu Hani\n";
} else {
    echo "HTML does NOT contain Abu Hani\n";
    if (strpos($html, 'المكتب الرئيسي') !== false) {
        echo "HTML contains Main Office\n";
    }
}
