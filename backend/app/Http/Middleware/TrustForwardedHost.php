<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Align URL generation and session cookies with the browser-visible origin.
 *
 * Required for Cloudflare Tunnel and other TLS-terminating proxies where APP_URL
 * still points at localhost but clients use a temporary public HTTPS hostname.
 */
class TrustForwardedHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $root = $request->getSchemeAndHttpHost();

        config(['app.url' => $root]);

        if ($request->isSecure()) {
            URL::forceScheme('https');
            config(['session.secure' => true]);
        } elseif (filter_var(env('SESSION_SECURE_COOKIE'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null) {
            config(['session.secure' => filter_var(env('SESSION_SECURE_COOKIE'), FILTER_VALIDATE_BOOLEAN)]);
        }

        $sessionDomain = env('SESSION_DOMAIN');
        if ($sessionDomain === null || $sessionDomain === '' || $sessionDomain === 'null') {
            config(['session.domain' => null]);
        }

        return $next($request);
    }
}
