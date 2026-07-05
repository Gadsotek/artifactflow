<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Infrastructure\Security\OriginNormalizer;
use PHPUnit\Framework\TestCase;

final class OriginNormalizerTest extends TestCase
{
    public function test_compact_omits_default_ports_and_keeps_explicit_ones(): void
    {
        $this->assertSame('https://app.example.test', OriginNormalizer::tryParse('https://app.example.test')?->compact());
        $this->assertSame('https://app.example.test', OriginNormalizer::tryParse('https://app.example.test:443')?->compact());
        $this->assertSame('http://app.example.test', OriginNormalizer::tryParse('http://app.example.test:80')?->compact());
        $this->assertSame('https://app.example.test:8443', OriginNormalizer::tryParse('https://app.example.test:8443')?->compact());
        $this->assertSame('http://localhost:18080', OriginNormalizer::tryParse('http://localhost:18080/some/path')?->compact());
    }

    public function test_canonical_always_carries_the_resolved_port(): void
    {
        $this->assertSame('https://app.example.test:443', OriginNormalizer::tryParse('https://app.example.test')?->canonical());
        $this->assertSame('http://app.example.test:80', OriginNormalizer::tryParse('http://app.example.test')?->canonical());
        $this->assertSame('https://app.example.test:8443', OriginNormalizer::tryParse('https://app.example.test:8443')?->canonical());
    }

    public function test_scheme_and_host_are_lowercased_and_whitespace_trimmed(): void
    {
        $origin = OriginNormalizer::tryParse('  HTTPS://App.Example.TEST:8443/x  ');

        $this->assertNotNull($origin);
        $this->assertSame('https', $origin->scheme);
        $this->assertSame('app.example.test', $origin->host);
        $this->assertSame(8443, $origin->port);
        $this->assertTrue($origin->isHttps());
    }

    public function test_invalid_or_non_http_origins_return_null(): void
    {
        $this->assertNull(OriginNormalizer::tryParse(''));
        $this->assertNull(OriginNormalizer::tryParse('   '));
        $this->assertNull(OriginNormalizer::tryParse('not-a-url'));
        $this->assertNull(OriginNormalizer::tryParse('/relative/path'));
        $this->assertNull(OriginNormalizer::tryParse('ftp://app.example.test'));
        $this->assertNull(OriginNormalizer::tryParse('app.example.test'));
        $this->assertNull(OriginNormalizer::tryParse('https://'));
    }

    public function test_try_host_reads_url_or_bare_host_and_rejects_wildcard(): void
    {
        $this->assertSame('app.example.test', OriginNormalizer::tryHost('https://app.example.test:8443/path'));
        $this->assertSame('app.example.test', OriginNormalizer::tryHost('app.example.test'));
        $this->assertSame('app.example.test', OriginNormalizer::tryHost('APP.EXAMPLE.TEST'));
        $this->assertNull(OriginNormalizer::tryHost('*'));
        $this->assertNull(OriginNormalizer::tryHost(''));
        $this->assertNull(OriginNormalizer::tryHost('   '));
    }

    public function test_non_ascii_unicode_hosts_are_rejected_so_only_punycode_is_accepted(): void
    {
        // A browser canonicalizes an IDN host to its ASCII punycode (xn--) form, so a
        // raw unicode spelling must be refused rather than compared byte-wise -- which
        // would silently defeat the host-separation and session-domain guards. The
        // punycode form is accepted; uppercase multibyte is rejected regardless of case.
        $this->assertNull(OriginNormalizer::tryParse('https://bücher.example'));
        $this->assertNull(OriginNormalizer::tryParse('https://BÜCHER.example'));
        $this->assertNull(OriginNormalizer::tryHost('https://artifacts.bücher.example'));
        $this->assertNull(OriginNormalizer::tryHost('bücher.example'));

        $this->assertSame('xn--bcher-kva.example', OriginNormalizer::tryParse('https://xn--bcher-kva.example')?->host);
        $this->assertSame('xn--bcher-kva.example', OriginNormalizer::tryHost('https://xn--bcher-kva.example'));
    }

    public function test_non_canonical_ipv4_alias_hosts_are_rejected(): void
    {
        // Browsers canonicalize IPv4 shorthand, integer, hex, and octal spellings to a
        // dotted-quad, so two configs spelling the same loopback address differently
        // would target one origin while comparing unequal byte-wise here -- defeating
        // the app/artifact host-separation gate. Fail closed on every non-canonical
        // numeric form; only the canonical dotted-quad is accepted.
        $this->assertNull(OriginNormalizer::tryParse('https://127.1'));
        $this->assertNull(OriginNormalizer::tryParse('https://2130706433'));
        $this->assertNull(OriginNormalizer::tryParse('https://0x7f.0.0.1'));
        $this->assertNull(OriginNormalizer::tryParse('https://0177.0.0.1'));
        $this->assertNull(OriginNormalizer::tryHost('127.1'));
        $this->assertNull(OriginNormalizer::tryHost('2130706433'));

        $canonical = OriginNormalizer::tryParse('https://127.0.0.1:8443');
        $this->assertNotNull($canonical);
        $this->assertSame('127.0.0.1', $canonical->host);
        $this->assertSame('127.0.0.1', OriginNormalizer::tryHost('127.0.0.1'));
    }

    public function test_ipv6_hosts_are_canonicalised_so_equivalent_spellings_compare_equal(): void
    {
        // The compressed and fully-expanded spellings of an IPv6 address denote the
        // same browser origin. Canonicalize both to the compressed form so the
        // host-separation and origin-equality guards see them as equal; reject a
        // malformed literal rather than pass it through unnormalized.
        $compressed = OriginNormalizer::tryParse('https://[::1]:443');
        $expanded = OriginNormalizer::tryParse('https://[0:0:0:0:0:0:0:1]:443');

        $this->assertNotNull($compressed);
        $this->assertNotNull($expanded);
        $this->assertSame('[::1]', $compressed->host);
        $this->assertSame($compressed->canonical(), $expanded->canonical());

        $this->assertSame('[::1]', OriginNormalizer::tryHost('[0:0:0:0:0:0:0:1]'));
        $this->assertNull(OriginNormalizer::tryParse('https://[::zz]'));
    }

    public function test_percent_escaped_or_backslash_hosts_are_rejected(): void
    {
        // PHP keeps a percent-escape or backslash in the host verbatim, but a browser
        // rewrites it (%61 -> "a", "\\" -> "/"), so two configs a browser resolves to one
        // origin would compare unequal byte-wise here. Fail closed rather than compare a
        // spelling the browser never sees.
        $this->assertNull(OriginNormalizer::tryParse('https://%61pp.example.test'));
        $this->assertNull(OriginNormalizer::tryParse('https://app%2eexample.test'));
        $this->assertNull(OriginNormalizer::tryHost('%61pp.example.test'));
    }

    public function test_pure_origin_parser_rejects_anything_beyond_scheme_host_port(): void
    {
        // A production origin must be a bare origin. parse_url() silently drops a userinfo,
        // path, query, or fragment and keeps only the origin, but a browser resolves several
        // of them differently -- so reject them outright instead of normalizing the ambiguity
        // away. A bare origin (optionally with a lone root slash) is still accepted.
        $this->assertNotNull(OriginNormalizer::tryParsePureOrigin('https://app.example.test'));
        $this->assertNotNull(OriginNormalizer::tryParsePureOrigin('https://app.example.test/'));
        $this->assertSame(
            'https://app.example.test:8443',
            OriginNormalizer::tryParsePureOrigin('https://app.example.test:8443')?->canonical(),
        );

        $this->assertNull(OriginNormalizer::tryParsePureOrigin('https://evil@app.example.test'));
        $this->assertNull(OriginNormalizer::tryParsePureOrigin('https://user:pass@app.example.test'));
        $this->assertNull(OriginNormalizer::tryParsePureOrigin('https://app.example.test/pages'));
        $this->assertNull(OriginNormalizer::tryParsePureOrigin('https://app.example.test?next=/x'));
        $this->assertNull(OriginNormalizer::tryParsePureOrigin('https://app.example.test#frag'));
        $this->assertNull(OriginNormalizer::tryParsePureOrigin('https://app.example.test\\@evil.example'));
        $this->assertNull(OriginNormalizer::tryParsePureOrigin('https://%61pp.example.test'));
        $this->assertNull(OriginNormalizer::tryParsePureOrigin('ftp://app.example.test'));
        $this->assertNull(OriginNormalizer::tryParsePureOrigin(''));
    }
}
