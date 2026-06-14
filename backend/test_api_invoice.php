<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Operation;
use App\Models\User;
use Illuminate\Http\Request;

$operation = Operation::withoutGlobalScopes()->first();
$operation->office_id = 2;
$operation->saveQuietly();

$user = User::where('role', 'super_admin')->first();
auth()->login($user);

$request = Request::create('/api/exports/operations/' . $operation->id . '/invoice?format=pdf', 'GET');
$request->headers->set('X-Office-Id', 2);

$response = app()->handle($request);
if ($response->getStatusCode() === 200) {
    echo "SUCCESS\n";
    $content = $response->getContent();
    file_put_contents('test_invoice.pdf', $content);
} else {
    echo "FAIL: " . $response->getStatusCode() . "\n";
    echo $response->getContent();
}
