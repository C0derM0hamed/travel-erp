<?php

namespace App\Support;

use Laravel\Sanctum\Sanctum;

class SanctumStatefulDomains
{
    /**
     * Hosts that receive Sanctum session / CSRF middleware on API routes.
     *
     * Always includes the current request host placeholder so temporary
     * Cloudflare Tunnel URLs work without editing .env on every run.
     */
    public static function resolve(): array
    {
        $configured = env('SANCTUM_STATEFUL_DOMAINS', 'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1');

        $domains = array_merge(
            explode(',', (string) $configured),
            [Sanctum::$currentRequestHostPlaceholder],
        );

        return array_values(array_unique(array_filter(array_map(
            fn (string $domain) => self::normalizeHost($domain),
            $domains,
        ))));
    }

    public static function normalizeHost(string $domain): ?string
    {
        $domain = trim($domain);

        if ($domain === '' || $domain === Sanctum::$currentRequestHostPlaceholder) {
            return $domain === '' ? null : $domain;
        }

        if (str_contains($domain, '://')) {
            $host = parse_url($domain, PHP_URL_HOST);
            $port = parse_url($domain, PHP_URL_PORT);

            if (! $host) {
                return null;
            }

            return $port ? "{$host}:{$port}" : $host;
        }

        return rtrim($domain, '/');
    }
}
