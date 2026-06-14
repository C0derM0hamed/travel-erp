<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = app(\App\Services\OfficeProvisioningService::class);
try {
    $office = $service->createOffice([
        'office_code' => 'OFC99',
        'office_name' => 'Test 99',
    ]);
    echo "SUCCESS: " . $office->id;
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
