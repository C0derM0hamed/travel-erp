<?php

namespace App\Http\Middleware;

use App\Models\Office;
use App\Support\OfficeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOfficeContext
{
    public function __construct(private OfficeContext $officeContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->role === 'super_admin') {
            $officeId = $this->resolveSuperAdminOfficeId($request);
            if ($officeId) {
                $this->officeContext->setOfficeId($officeId);
                $request->session()->put('current_office_id', $officeId);
            }
        } else {
            if (! $user->office_id) {
                return response()->json(['message' => 'المستخدم غير مرتبط بمكتب'], 403);
            }

            $office = Office::find($user->office_id);
            if (! $office || ! $office->is_active) {
                return response()->json(['message' => 'المكتب غير مفعل'], 403);
            }

            $this->officeContext->setOfficeId((int) $user->office_id);
            $request->session()->put('current_office_id', (int) $user->office_id);
        }

        return $next($request);
    }

    private function resolveSuperAdminOfficeId(Request $request): ?int
    {
        $header = $request->header('X-Office-Id');
        if ($header && Office::whereKey($header)->exists()) {
            return (int) $header;
        }

        $query = $request->query('office_id');
        if ($query && Office::whereKey($query)->exists()) {
            return (int) $query;
        }

        $session = $request->session()->get('current_office_id');
        if ($session && Office::whereKey($session)->exists()) {
            return (int) $session;
        }

        return Office::where('is_active', true)->orderBy('id')->value('id');
    }
}
