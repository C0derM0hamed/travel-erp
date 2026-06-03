<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;

/**
 * Enables Sanctum session middleware for API routes from configured stateful hosts.
 *
 * Stock Sanctum only treats requests as "frontend" when Referer/Origin match,
 * which breaks cookie auth in Postman, curl, and other API clients that send
 * session cookies but no Origin header. Browsers still pass via Referer/Origin.
 */
class EnsureStatefulApiRequests extends EnsureFrontendRequestsAreStateful
{
    public static function fromFrontend($request): bool
    {
        if (parent::fromFrontend($request)) {
            return true;
        }

        $host = $request->getHttpHost();

        foreach (array_filter(config('sanctum.stateful', [])) as $domain) {
            if ($domain === Sanctum::$currentRequestHostPlaceholder) {
                $domain = $host;
            }

            if ($host === trim($domain)) {
                return true;
            }
        }

        return false;
    }
}
