<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Office;
use App\Models\User;
use Illuminate\Http\Request;

$office = Office::firstOrCreate(
    ['office_code' => 'ABUHANI'],
    ['office_name' => 'Office Abu Hani', 'logo' => 'abu_hani.png', 'is_active' => true]
);

$user = User::where('role', 'super_admin')->first();
auth()->login($user);

$request = Request::create('/api/exports/reports/profit?format=pdf', 'GET');
$request->headers->set('X-Office-Id', $office->id);

$response = app()->handle($request);

if ($response->getStatusCode() === 200) {
    echo "SUCCESS\n";
    $content = $response->getContent();
    // Mpdf output is compressed, but usually we can find some uncompressed text if not fully compressed, 
    // or just assume if it returns 200, we'll check how ExportContext handled it.
    // Instead of parsing PDF, let's just use the router to call the method directly to intercept the data.
} else {
    echo "FAIL: " . $response->getStatusCode() . "\n";
    echo $response->getContent();
}
