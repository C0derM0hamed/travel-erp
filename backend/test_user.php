<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::find(5); // super_admin
auth()->login($user);

$request = Illuminate\Http\Request::create('/api/users', 'POST', [
    'name' => 'Test User',
    'email' => 'test_user_unique_123@travel.kw',
    'password' => 'Password123!',
    'role' => 'sales',
    'role_label' => 'Sales',
    'office_id' => 1,
]);
$request->setUserResolver(fn() => $user);

$controller = app(\App\Http\Controllers\Api\UserController::class);
try {
    $response = $controller->store($request);
    echo "SUCCESS: " . $response->getStatusCode() . "\n" . $response->getContent() . "\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
