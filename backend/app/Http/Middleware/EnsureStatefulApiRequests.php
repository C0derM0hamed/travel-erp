<?php

namespace App\Http\Middleware;

use App\Support\SanctumStatefulDomains;
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
        if (self::isSameOriginRequest($request)) {
            return true;
        }

        if (parent::fromFrontend($request)) {
            return true;
        }

        $host = $request->getHttpHost();

        foreach (config('sanctum.stateful', SanctumStatefulDomains::resolve()) as $domain) {
            if ($domain === Sanctum::$currentRequestHostPlaceholder) {
                $domain = $host;
            } else {
                $domain = SanctumStatefulDomains::normalizeHost($domain) ?? $domain;
            }

            if (strcasecmp($host, trim($domain)) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function configureSecureCookieSessions(): void
    {
        parent::configureSecureCookieSessions();

        if ($this->requestIsSecure()) {
            config([
                'session.secure' => true,
                'session.same_site' => 'lax',
            ]);
        }
    }

    private static function isSameOriginRequest($request): bool
    {
        $host = $request->getHost();

        foreach (['Origin', 'Referer'] as $header) {
            $value = $request->headers->get($header);

            if (! $value) {
                continue;
            }

            $sourceHost = parse_url($value, PHP_URL_HOST);

            if ($sourceHost && strcasecmp($sourceHost, $host) === 0) {
                return true;
            }
        }

        return false;
    }

    private function requestIsSecure(): bool
    {
        $request = request();

        return $request && $request->isSecure();
    }
}
