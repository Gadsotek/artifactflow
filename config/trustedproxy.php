<?php

declare(strict_types=1);

$configuredProxies = env('TRUSTED_PROXIES');
$trustedProxies = null;
$rawTrustedProxies = is_string($configuredProxies) ? trim($configuredProxies) : '';

if (is_string($configuredProxies)) {
    if ($rawTrustedProxies !== '') {
        $trustedProxies = str_contains($rawTrustedProxies, ',')
            ? array_values(array_filter(
                array_map('trim', explode(',', $rawTrustedProxies)),
                static fn (string $proxy): bool => $proxy !== '',
            ))
            : $rawTrustedProxies;
    }
}

return [
    'raw' => $rawTrustedProxies,
    'proxies' => $trustedProxies,
];
