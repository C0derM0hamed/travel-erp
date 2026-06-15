<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Office;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;

$office = Office::first();
$user = User::where('role', 'super_admin')->first();
auth()->login($user);

// Create a dummy image
$imagePath = __DIR__.'/storage/app/dummy.png';
$image = imagecreatetruecolor(100, 100);
imagepng($image, $imagePath);
imagedestroy($image);

$file = new UploadedFile(
    $imagePath,
    'dummy.png',
    'image/png',
    null,
    true
);

$request = Request::create('/api/offices/'.$office->id.'/logo', 'POST', [], [], ['logo' => $file]);

$response = app()->handle($request);
echo "Status: " . $response->getStatusCode() . "\n";
echo "Content: " . $response->getContent() . "\n";

