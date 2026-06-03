<?php

/**
 * CLI production audit — run from backend/: php scripts/production-audit.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

$issues = [];
$password = env('SEED_USER_PASSWORD');
if (! $password) {
    $issues[] = ['critical', 'SEED_USER_PASSWORD not set in .env'];
}

if (env('APP_DEBUG') && env('APP_ENV') !== 'testing') {
    $issues[] = ['high', 'APP_DEBUG=true — must be false in production'];
}

$debit = (float) JournalEntry::sum('debit');
$credit = (float) JournalEntry::sum('credit');
if (abs($debit - $credit) > 0.01) {
    $issues[] = ['critical', "Journal unbalanced: debit=$debit credit=$credit"];
}

$admin = User::where('email', 'admin@travel.kw')->first();
if (! $admin || ! $password || ! Hash::check($password, $admin->password)) {
    $issues[] = ['critical', 'Admin login password mismatch with SEED_USER_PASSWORD'];
}

if (! Schema::hasColumn('clients', 'phone')) {
    $issues[] = ['critical', 'clients table missing — run migrations'];
} else {
    foreach (['clients' => 'phone', 'vendors' => 'name'] as $table => $col) {
        $indexes = Schema::getIndexes($table);
        $hasUnique = collect($indexes)->contains(fn ($idx) => $idx['unique'] && in_array($col, $idx['columns'], true));
        if (! $hasUnique) {
            $issues[] = ['high', "Missing unique index on {$table}.{$col} — run php artisan migrate"];
        }
    }
}

$html = @file_get_contents(dirname(__DIR__).'/../frontend/travelsystemv3.html') ?: '';
foreach (["password:'123456'", 'login-hint', 'generateJournal', 'alert('] as $needle) {
    if ($html !== '' && str_contains($html, $needle)) {
        $issues[] = ['high', "Frontend contains forbidden pattern: $needle"];
    }
}

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
Auth::login($admin);

foreach (['operations', 'profit', 'aging', 'employee', 'cashflow', 'clients-debt', 'vendors-balance'] as $type) {
    $req = Illuminate\Http\Request::create("/api/reports/$type", 'GET');
    $req->headers->set('Accept', 'application/json');
    $req->setUserResolver(fn () => $admin);
    $res = $kernel->handle($req);
    if ($res->getStatusCode() !== 200) {
        $issues[] = ['high', "Report $type returned ".$res->getStatusCode()];
    }
}

$dupClientReq = Illuminate\Http\Request::create('/api/clients', 'POST', [
    'name' => 'Audit Dup',
    'phone' => Client::value('phone'),
]);
$dupClientReq->headers->set('Accept', 'application/json');
$dupClientReq->setUserResolver(fn () => $admin);
$dupClientRes = $kernel->handle($dupClientReq);
if ($dupClientRes->getStatusCode() !== 422) {
    $issues[] = ['critical', 'Duplicate client phone was accepted (expected 422, got '.$dupClientRes->getStatusCode().')'];
}

$dupVendorReq = Illuminate\Http\Request::create('/api/vendors', 'POST', [
    'name' => Vendor::value('name'),
    'category' => 'other',
]);
$dupVendorReq->headers->set('Accept', 'application/json');
$dupVendorReq->setUserResolver(fn () => $admin);
$dupVendorRes = $kernel->handle($dupVendorReq);
if ($dupVendorRes->getStatusCode() !== 422) {
    $issues[] = ['critical', 'Duplicate vendor name was accepted (expected 422, got '.$dupVendorRes->getStatusCode().')'];
}

echo "=== Production Audit CLI ===\n";
echo 'Journal entries: '.JournalEntry::count().' | Balanced: '.(abs($debit - $credit) < 0.01 ? 'yes' : 'no')."\n";
echo 'Users: '.User::count()."\n";
echo 'Clients: '.Client::count().' | Vendors: '.Vendor::count()."\n\n";

if (empty($issues)) {
    echo "No CLI issues found.\n";
    exit(0);
}

foreach ($issues as [$sev, $msg]) {
    echo "[$sev] $msg\n";
}
exit(count(array_filter($issues, fn ($i) => $i[0] === 'critical')) > 0 ? 1 : 2);
