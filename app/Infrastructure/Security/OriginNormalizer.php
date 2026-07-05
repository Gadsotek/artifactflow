<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * The single origin parser for the application. Every security boundary that
 * compares or renders an origin — the CSP security headers, the artifact
 * signed-URL origin check, the production boot gate, the deployment preflight,
 * and the realtime client configuration — must build its origins here so the
 * parsing and validation rule can never drift between them.
 *
 * Only http/https origins are accepted; scheme and host are lowercased and the
 * port is resolved to its explicit or scheme-default value.
 */
final class OriginNormalizer
{
    /**
     * @var array<string, int>
     */
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public static function tryParse(string $url): ?Origin
    {
        $parts = parse_url(trim($url));

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (!array_key_exists($scheme, self::DEFAULT_PORTS)) {
            return null;
        }

        $host = self::normalizeHost(strtolower((string) $parts['host']));

        if ($host === null) {
            return null;
        }

        $port = $parts['port'] ?? self::DEFAULT_PORTS[$scheme];

        return new Origin($scheme, $host, $port);
    }

    /**
     * Parse a *pure* origin: scheme://host[:port] and nothing else. Used for production
     * origin configuration (app.url, artifact_url, reverb, allowed origins), which must be a
     * bare origin so it resolves identically in PHP and in a browser.
     *
     * parse_url() silently accepts and drops a userinfo, path, query, or fragment, keeping
     * only the origin -- but a browser resolves several of those differently: `evil@host`
     * makes `host` the origin, a backslash is a path separator, and a percent-escape decodes
     * in the host. A production origin carrying any of them could be blessed by tryParse()
     * here yet point elsewhere in the browser, so reject them outright rather than normalize
     * away the ambiguity. (Percent-escapes and backslashes inside the host are already
     * rejected by normalizeHost; this additionally forbids them via the userinfo/path forms.)
     */
    public static function tryParsePureOrigin(string $url): ?Origin
    {
        $trimmed = trim($url);

        if ($trimmed === '' || str_contains($trimmed, '\\')) {
            return null;
        }

        $parts = parse_url($trimmed);

        if (!is_array($parts)) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')
        ) {
            return null;
        }

        return self::tryParse($trimmed);
    }

    /**
     * Extract just the lowercased host from either a full URL or a bare host.
     * Rejects empty and wildcard values. Used where configuration may hold a
     * bare host rather than a full origin (for example a Reverb allowed-origins
     * entry).
     */
    public static function tryHost(string $urlOrHost): ?string
    {
        $value = strtolower(trim($urlOrHost));

        if ($value === '' || $value === '*') {
            return null;
        }

        $parts = parse_url($value);

        if (!is_array($parts) || !isset($parts['host'])) {
            $parts = parse_url('https://' . $value);
        }

        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        return self::normalizeHost(strtolower((string) $parts['host']));
    }

    /**
     * Canonicalize a host so equivalent browser spellings compare equal here, and
     * fail closed on any form that a browser would silently rewrite to a different
     * byte string. Returns null to reject the host outright.
     *
     * Three hazards are handled, each of which would otherwise let two configs that
     * a browser resolves to one origin slip past the byte-equality origin/host and
     * session-domain guards that depend on this parser:
     *  - non-ASCII (unicode/IDN) hosts, which a browser rewrites to ASCII punycode;
     *  - IPv6 literals, whose compressed and expanded spellings denote one address;
     *  - non-canonical IPv4 aliases (shorthand, integer, hex, octal), which a
     *    browser canonicalizes to a dotted-quad.
     */
    private static function normalizeHost(string $host): ?string
    {
        if ($host === '' || self::hasNonAsciiBytes($host)) {
            return null;
        }

        // A percent-escape or backslash in the host is kept verbatim by PHP but rewritten by
        // browsers (%61 -> "a", "\\" -> "/"), so the byte-preserving host here would compare
        // unequal to the origin a browser actually resolves. Fail closed on both.
        if (str_contains($host, '%') || str_contains($host, '\\')) {
            return null;
        }

        // parse_url keeps the surrounding brackets on an IPv6 literal.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return self::canonicalIpv6($host);
        }

        if (self::looksLikeIpv4($host) && !self::isCanonicalIpv4($host)) {
            return null;
        }

        return $host;
    }

    /**
     * Reject non-ASCII (unicode) hosts. Browsers canonicalize an IDN host to its
     * ASCII punycode (xn--) form, so a raw-unicode spelling here would compare
     * unequal to that form. Operators must configure the punycode form; fail closed
     * on anything else rather than normalize inconsistently.
     */
    private static function hasNonAsciiBytes(string $host): bool
    {
        return preg_match('/[^\x00-\x7f]/', $host) === 1;
    }

    /**
     * Collapse an IPv6 literal to its canonical compressed form so, for example,
     * [::1] and [0:0:0:0:0:0:0:1] compare equal. Returns null for a malformed
     * literal rather than passing it through unnormalized.
     */
    private static function canonicalIpv6(string $host): ?string
    {
        $packed = @inet_pton(substr($host, 1, -1));

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $canonical = inet_ntop($packed);

        return $canonical === false ? null : '[' . $canonical . ']';
    }

    /**
     * A host whose every dot-separated label is purely numeric (decimal, 0x-hex, or
     * octal) is one a browser will parse as an IPv4 address rather than a DNS name.
     */
    private static function looksLikeIpv4(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The only IPv4 spelling accepted as-is: a four-part dotted-quad with each octet
     * in range and no octal-ambiguous leading zeros (a browser reads 010 as octal).
     */
    private static function isCanonicalIpv4(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return preg_match('/(?:^|\.)0[0-9]/', $host) !== 1;
    }
}
