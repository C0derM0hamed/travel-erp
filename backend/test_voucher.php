<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Voucher;
use App\Models\User;
use Illuminate\Http\Request;

$voucher = Voucher::withoutGlobalScopes()->first();
if (!$voucher) {
    echo "NO VOUCHER\n";
    exit;
}
$voucher->office_id = 2; // Abu Hani
$voucher->saveQuietly();

$user = User::where('role', 'super_admin')->first();
auth()->login($user);

// Simulate the frontend request
$request = Request::create('/api/exports/vouchers/' . $voucher->id . '?format=pdf', 'GET');
$request->headers->set('X-Office-Id', 2);

$response = app()->handle($request);
if ($response->getStatusCode() === 200) {
    echo "SUCCESS\n";
    file_put_contents('test_voucher.pdf', $response->getContent());
} else {
    echo "FAIL: " . $response->getStatusCode() . "\n";
    echo $response->getContent();
}
