<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Office;

foreach (Office::all() as $office) {
    echo "Office ID: {$office->id}, Code: {$office->office_code}, Logo: {$office->logo}\n";
}
