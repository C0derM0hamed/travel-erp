<?php

namespace App\Http\Middleware;

use App\Support\OfficeContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            return $next($request);
        }

        $path = '/'.$request->path();
        $method = $request->method();

        $existing = DB::table('idempotency_keys')->where('key', $key)->first();
        if ($existing) {
            if ($existing->response_code && $existing->response_body !== null) {
                return response()->json(json_decode($existing->response_body, true), (int) $existing->response_code);
            }

            return response()->json(['message' => 'طلب مكرر قيد المعالجة، يرجى الانتظار'], 409);
        }

        try {
            DB::table('idempotency_keys')->insert([
                'key' => $key,
                'user_id' => $request->user()?->id,
                'office_id' => app(OfficeContext::class)->id(),
                'method' => $method,
                'path' => $path,
                'expires_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            return response()->json(['message' => 'طلب مكرر قيد المعالجة، يرجى الانتظار'], 409);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            DB::table('idempotency_keys')->where('key', $key)->update([
                'response_code' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'updated_at' => now(),
            ]);
        }

        return $response;
    }
}
