<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Office;
use App\Models\User;
use Illuminate\Http\UploadedFile;

$office = Office::find(1);
$user = User::where('role', 'super_admin')->first();

$imagePath = __DIR__.'/dummy.png';
if (!file_exists($imagePath)) {
    $image = imagecreatetruecolor(100, 100);
    imagepng($image, $imagePath);
    imagedestroy($image);
}

$file = new UploadedFile(
    $imagePath,
    'dummy.png',
    'image/png',
    null,
    true
);

$request = \Illuminate\Http\Request::create(
    '/api/offices/'.$office->id.'/logo',
    'POST',
    [],
    [],
    ['logo' => $file]
);
$request->setUserResolver(fn() => $user);

// We need to bypass the middleware, so we call the controller directly
$controller = app(\App\Http\Controllers\Api\OfficeController::class);
$response = $controller->uploadLogo($request, $office, app(\App\Services\OfficeLogoService::class));

echo "Status: " . $response->getStatusCode() . "\n";
echo "Content: " . $response->getContent() . "\n";

