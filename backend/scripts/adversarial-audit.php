<?php

/**
 * Adversarial edge-case audit — run: php scripts/adversarial-audit.php
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AccountingService;
use Illuminate\Support\Facades\Schema;

$base = rtrim(env('APP_URL', 'http://127.0.0.1:8080'), '/');
$password = env('SEED_USER_PASSWORD');
if (! $password) {
    throw new RuntimeException('Set SEED_USER_PASSWORD before running adversarial-audit.php');
}
$findings = [];

function finding(string $category, string $severity, string $msg): void
{
    global $findings;
    $findings[] = compact('category', 'severity', 'msg');
    echo "[$severity][$category] $msg\n";
}

function api(string $method, string $path, ?array $body = null, array $cookies = [], ?string $csrf = null): array
{
    global $base;
    $ch = curl_init($base.$path);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    if ($csrf && in_array(strtoupper($method), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
        $headers[] = 'X-XSRF-TOKEN: '.$csrf;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIE => implode('; ', $cookies),
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    [$hdrs, $resp] = explode("\r\n\r\n", $raw, 2);
    preg_match_all('/Set-Cookie: ([^;]+)/', $hdrs, $m);
    $newCookies = $cookies;
    foreach ($m[1] ?? [] as $c) {
        [$k] = explode('=', $c, 2);
        $newCookies[$k] = $c;
    }
    $xsrf = null;
    foreach ($newCookies as $c) {
        if (str_starts_with($c, 'XSRF-TOKEN=')) {
            $xsrf = urldecode(substr($c, strlen('XSRF-TOKEN=')));
        }
    }

    return ['code' => $code, 'body' => json_decode($resp, true), 'raw' => $resp, 'cookies' => $newCookies, 'csrf' => $xsrf];
}

function loginAs(string $email): array
{
    global $password;
    $s = api('GET', '/sanctum/csrf-cookie');
    $s = api('POST', '/api/login', ['email' => $email, 'password' => $password], $s['cookies'], $s['csrf']);
    $s = api('GET', '/sanctum/csrf-cookie', null, $s['cookies']);
    if ($s['code'] !== 204 && $s['code'] !== 200) {
        throw new RuntimeException("Login failed for $email: ".$s['code']);
    }

    return $s;
}

function authed(string $email): array
{
    $s = loginAs($email);

    return ['cookies' => $s['cookies'], 'csrf' => $s['csrf']];
}

function post(array $session, string $path, array $body): array
{
    return api('POST', $path, $body, $session['cookies'], $session['csrf']);
}

echo "=== Adversarial Audit ===\n";
echo "Base: $base\n\n";

// --- DATA INTEGRITY: phone formatting bypass ---
$sales = authed('sales@travel.kw');
$basePhone = '8800'.random_int(1000, 9999);
$r1 = post($sales, '/api/clients', ['name' => 'Phone Test A', 'phone' => '0'.$basePhone]);
if ($r1['code'] === 201) {
    $variants = [
        $basePhone,
        '+965'.$basePhone,
        ' 0'.$basePhone.' ',
        '965'.$basePhone,
    ];
    foreach ($variants as $v) {
        $r = post($sales, '/api/clients', ['name' => 'Phone Dup '.md5($v), 'phone' => $v]);
        if ($r['code'] === 201) {
            finding('data_integrity', 'HIGH', "Duplicate client created with phone variant '$v' (original '0$basePhone') — no phone normalization beyond whitespace");
        }
    }
} else {
    finding('data_integrity', 'INFO', 'Could not seed phone test client: '.$r1['code']);
}

// Leading/trailing spaces on vendor name (trim only, not unicode normalize)
$admin = authed('admin@travel.kw');
$vn = 'Edge Vendor '.random_int(1000, 9999);
post($admin, '/api/vendors', ['name' => $vn, 'category' => 'other']);
$r = post($admin, '/api/vendors', ['name' => '  '.$vn.'  ', 'category' => 'other']);
if ($r['code'] === 422) {
    finding('data_integrity', 'OK', 'Vendor duplicate with spaces correctly rejected (trimmed)');
} elseif ($r['code'] === 201) {
    finding('data_integrity', 'MEDIUM', 'Vendor duplicate with leading/trailing spaces accepted as new record');
}

// Emoji / unicode in names
$r = post($sales, '/api/clients', ['name' => 'Test 😀🎉 Client', 'phone' => '77'.random_int(100000, 999999)]);
if ($r['code'] === 201) {
    finding('data_integrity', 'INFO', 'Emoji in client name accepted (may break Excel/export or search)');
}

// Very long notes
$long = str_repeat('أ', 5000);
$r = post($sales, '/api/clients', ['name' => 'Long Notes', 'phone' => '66'.random_int(100000, 999999), 'notes' => $long]);
if ($r['code'] === 201) {
    finding('data_integrity', 'MEDIUM', 'Client notes accepted at 5000 chars (validation has no max on notes)');
}

// --- ACCOUNTING ATTACKS ---
$acct = authed('accountant@travel.kw');
$client = post($sales, '/api/clients', ['name' => 'Accounting Attack', 'phone' => '55'.random_int(100000, 999999)]);
if ($client['code'] === 201) {
    $cid = $client['body']['id'];
    $op = post($sales, '/api/operations', [
        'client_id' => $cid, 'service_id' => 1, 'vendor_id' => 1,
        'client_price' => 100, 'vendor_cost' => 150, 'initial_payment' => 0,
    ]);
    if ($op['code'] === 201) {
        $oid = $op['body']['id'];
        $profit = $op['body']['profit'] ?? null;
        if ($profit !== null && $profit < 0) {
            finding('accounting', 'HIGH', "Negative profit allowed: client_price=100, vendor_cost=150 → profit=$profit (loss-making sale accepted)");
        }
        // Multiple vouchers exceeding operation amount
        post($acct, '/api/vouchers', ['type' => 'receipt', 'party_type' => 'client', 'party_id' => $cid, 'amount' => 80, 'safe_id' => 1, 'operation_id' => $oid]);
        post($acct, '/api/vouchers', ['type' => 'receipt', 'party_type' => 'client', 'party_id' => $cid, 'amount' => 80, 'safe_id' => 1, 'operation_id' => $oid]);
        $bal = app(AccountingService::class)->clientBalance($cid);
        if ($bal < -50) {
            finding('accounting', 'CRITICAL', "Client over-credited: balance=$bal after receipts totaling 160 on 100 KWD operation — no overpayment guard");
        } elseif ($bal < 0) {
            finding('accounting', 'HIGH', "Client credit balance ($bal) from over-receipts on single operation — vouchers not capped to outstanding");
        }
    }
}

// Safe overdraft guard
$safeBalance = app(AccountingService::class)->safeBalance(1);
$r = post($acct, '/api/vouchers', ['type' => 'payment', 'party_type' => 'general', 'amount' => $safeBalance + 1, 'safe_id' => 1]);
if ($r['code'] === 201) {
    finding('accounting', 'CRITICAL', 'Payment exceeding safe balance was accepted — safe can go negative');
} elseif ($r['code'] === 422) {
    finding('accounting', 'OK', 'Payment exceeding safe balance correctly rejected');
}

// Vendor per-operation cap
$vc = post($sales, '/api/clients', ['name' => 'Vendor Cap Audit', 'phone' => '54'.random_int(100000, 999999)]);
if ($vc['code'] === 201) {
    $vop1 = post($sales, '/api/operations', [
        'client_id' => $vc['body']['id'], 'service_id' => 1, 'vendor_id' => 1,
        'client_price' => 200, 'vendor_cost' => 100, 'initial_payment' => 0,
    ]);
    post($sales, '/api/operations', [
        'client_id' => $vc['body']['id'], 'service_id' => 1, 'vendor_id' => 1,
        'client_price' => 500, 'vendor_cost' => 400, 'initial_payment' => 0,
    ]);
    if ($vop1['code'] === 201) {
        $r = post($acct, '/api/vouchers', ['type' => 'payment', 'party_type' => 'vendor', 'party_id' => 1, 'amount' => 150, 'safe_id' => 2, 'operation_id' => $vop1['body']['id']]);
        if ($r['code'] === 201) {
            finding('accounting', 'HIGH', 'Vendor payment linked to one operation exceeded that operation outstanding balance');
        } elseif ($r['code'] === 422) {
            finding('accounting', 'OK', 'Vendor payment is capped to linked operation outstanding balance');
        }
    }
}

// Decimal edge case
$r = post($sales, '/api/operations', [
    'client_id' => 1, 'service_id' => 1, 'vendor_id' => 1,
    'client_price' => 0.001, 'vendor_cost' => 0.002, 'initial_payment' => 0,
]);
if ($r['code'] === 201) {
    finding('accounting', 'MEDIUM', 'Sub-cent amounts accepted (0.001 KWD) — may cause rounding display issues');
} elseif ($r['code'] === 422) {
    finding('accounting', 'OK', 'Sub-cent amounts rejected');
}

// Large values
$r = post($sales, '/api/operations', [
    'client_id' => 1, 'service_id' => 1, 'vendor_id' => 1,
    'client_price' => 999999999.999, 'vendor_cost' => 999999999.998, 'initial_payment' => 0,
]);
if ($r['code'] === 201) {
    $debit = JournalEntry::sum('debit');
    $credit = JournalEntry::sum('credit');
    if (abs($debit - $credit) > 0.01) {
        finding('accounting', 'CRITICAL', "Journal unbalanced after max amounts: debit=$debit credit=$credit");
    } else {
        finding('accounting', 'INFO', 'Max boundary amounts accepted and journal stays balanced');
    }
}

// Cancel after vouchers — report mismatch
$cancelClient = post($sales, '/api/clients', ['name' => 'Cancel Report Test', 'phone' => '44'.random_int(100000, 999999)]);
if ($cancelClient['code'] === 201) {
    $ccid = $cancelClient['body']['id'];
    $cop = post($sales, '/api/operations', [
        'client_id' => $ccid, 'service_id' => 1, 'vendor_id' => 1,
        'client_price' => 200, 'vendor_cost' => 100, 'initial_payment' => 50,
    ]);
    if ($cop['code'] === 201) {
        post($acct, '/api/vouchers', ['type' => 'receipt', 'party_type' => 'client', 'party_id' => $ccid, 'amount' => 30, 'safe_id' => 1, 'operation_id' => $cop['body']['id']]);
        post($sales, '/api/operations/'.$cop['body']['id'].'/cancel', []);
        $stmt = api('GET', '/api/clients/'.$ccid.'/statement', null, $sales['cookies']);
        $paid = $stmt['body']['paid'] ?? 0;
        $balance = $stmt['body']['balance'] ?? 0;
        if ($paid > 0 && abs($balance) < 0.01) {
            finding('reports', 'HIGH', "Client statement shows paid=$paid but balance=$balance after cancel — 'paid' counts non-reversed voucher totals");
        }
        $report = api('GET', '/api/reports/clients-debt', null, $admin['cookies']);
        // client should not be in debt report
    }
}

// Double cancel via API
$opForCancel = post($sales, '/api/operations', [
    'client_id' => 1, 'service_id' => 1, 'vendor_id' => 1,
    'client_price' => 50, 'vendor_cost' => 30, 'initial_payment' => 0,
]);
if ($opForCancel['code'] === 201) {
    $oid = $opForCancel['body']['id'];
    post($sales, '/api/operations/'.$oid.'/cancel', []);
    $r2 = post($sales, '/api/operations/'.$oid.'/cancel', []);
    if ($r2['code'] !== 422) {
        finding('accounting', 'CRITICAL', 'Double cancel returned '.$r2['code'].' instead of 422');
    }
}

// --- CONCURRENCY: parallel operation creates ---
echo "\n--- Concurrency test (5 parallel operations) ---\n";
$concSession = authed('sales@travel.kw');
$concClient = post($concSession, '/api/clients', ['name' => 'Concurrency Client', 'phone' => '33'.random_int(100000, 999999)]);
$ccid = $concClient['body']['id'] ?? 1;
$mh = curl_multi_init();
$handles = [];
$refsBefore = Operation::pluck('ref')->all();
for ($i = 0; $i < 5; $i++) {
    $ch = curl_init($base.'/api/operations');
    $body = json_encode(['client_id' => $ccid, 'service_id' => 1, 'vendor_id' => 1, 'client_price' => 10 + $i, 'vendor_cost' => 5, 'initial_payment' => 0]);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-XSRF-TOKEN: '.$concSession['csrf']],
        CURLOPT_COOKIE => implode('; ', $concSession['cookies']),
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
} while ($running);
$codes = [];
foreach ($handles as $ch) {
    $codes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);
$newOps = Operation::where('client_id', $ccid)->pluck('ref');
$dupRefs = $newOps->count() !== $newOps->unique()->count();
if ($dupRefs) {
    finding('concurrency', 'CRITICAL', 'Duplicate operation refs after parallel creates: '.$newOps->implode(', '));
} else {
    finding('concurrency', 'OK', 'Parallel operation creates produced unique refs ('.implode(',', $codes).')');
}

// Journal balance check
$debit = (float) JournalEntry::sum('debit');
$credit = (float) JournalEntry::sum('credit');
if (abs($debit - $credit) > 0.01) {
    finding('accounting', 'CRITICAL', "Final journal UNBALANCED: debit=$debit credit=$credit");
} else {
    finding('accounting', 'OK', 'Journal balanced after adversarial tests');
}

// --- API / docs gaps ---
$noAuth = api('GET', '/api/bootstrap');
if ($noAuth['code'] === 401) {
    finding('docs', 'OK', 'Unauthenticated bootstrap returns 401');
}
$badReport = api('GET', '/api/reports/nonexistent', null, $admin['cookies']);
if ($badReport['code'] === 404) {
    finding('docs', 'OK', 'Unknown report type returns 404');
}

// Pagination probe
$pag = api('GET', '/api/clients?page=2&per_page=10', null, $admin['cookies']);
if (isset($pag['body']['meta']['current_page'])) {
    finding('pagination', 'OK', 'API list endpoints return pagination meta');
} else {
    finding('pagination', 'HIGH', 'API has no server-side pagination (page/per_page ignored)');
}

// Bootstrap no longer embeds full journal
$boot = api('GET', '/api/bootstrap', null, $admin['cookies']);
if (isset($boot['body']['metrics']) && ! array_key_exists('journal', $boot['body'] ?? [])) {
    finding('production', 'OK', 'Bootstrap returns metrics without full journal payload');
}
$size = strlen(json_encode($boot['body'] ?? []));
if ($size > 500000) {
    finding('production', 'HIGH', 'Bootstrap payload already '.round($size / 1024).'KB — will degrade with real data volume');
}

// --- DEPLOYMENT ---
if (! env('SANCTUM_STATEFUL_DOMAINS')) {
    finding('deployment', 'CRITICAL', 'SANCTUM_STATEFUL_DOMAINS not set');
}

if (Schema::hasTable('reference_sequences')) {
    finding('data_integrity', 'OK', 'Monotonic reference_sequences table present — refs survive row deletes');
} else {
    finding('data_integrity', 'HIGH', 'reference_sequences table missing — run php artisan migrate');
}

echo "\n=== Summary: ".count($findings)." findings ===\n";
foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO', 'OK'] as $sev) {
    $c = count(array_filter($findings, fn ($f) => $f['severity'] === $sev));
    if ($c) {
        echo "  $sev: $c\n";
    }
}
exit(count(array_filter($findings, fn ($f) => in_array($f['severity'], ['CRITICAL', 'HIGH'], true))) > 0 ? 1 : 0);
