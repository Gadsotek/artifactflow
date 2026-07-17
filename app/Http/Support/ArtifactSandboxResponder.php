<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Application\PageCatalog\ArtifactPreviewDocumentGuard;
use App\Infrastructure\Security\OriginNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Canonical builder for the sandboxed artifact-host HTML response. Both the
 * saved-artifact preview and the pre-save draft preview render user HTML on the
 * isolated artifact origin, and must share exactly one hardened CSP, guard
 * injection, and top-level-navigation policy. Keep that boundary defined here,
 * never duplicated per controller.
 */
final readonly class ArtifactSandboxResponder
{
    public function __construct(
        private ArtifactPreviewDocumentGuard $documentGuard,
    ) {
    }

    /**
     * Hardened 200 response for artifact HTML: defense-in-depth guard injection
     * on top of the real boundary (opaque-origin sandbox iframe + strict CSP).
     */
    public function document(string $html, bool $recoveryEnabled = false): Response
    {
        return response(
            $this->documentGuard->harden($html, $recoveryEnabled),
            200,
            $this->securityHeaders(),
        );
    }

    /**
     * Artifacts are only safe while embedded in the sandboxed iframe. Rendered as a
     * top-level document the iframe sandbox no longer applies, which would restore
     * downloads, self-initiated fullscreen/pointer-lock, and same-origin storage on
     * the shared artifact host. The browser sets Sec-Fetch-Dest and page script cannot
     * forge it. Absent (legacy browsers or a proxy that strips the hint) fails open so
     * embedding keeps working; any explicit non-iframe destination is refused.
     */
    public function isTopLevelNavigation(Request $request): bool
    {
        $destination = strtolower(trim((string) $request->headers->get('Sec-Fetch-Dest', '')));

        return $destination !== '' && $destination !== 'iframe';
    }

    /**
     * Stricter, fail-CLOSED companion for the capability-protected draft-preview endpoint: it
     * serves only an explicit iframe embed. The legitimate draft POST is a form
     * submission into the sandbox iframe and always carries Sec-Fetch-Dest: iframe,
     * so anything else — an absent header, a top-level navigation, a cross-site
     * fetch — is refused. This removes the top-level reflection surface that the
     * saved path (signature-gated) can afford to leave fail-open for embedding
     * compatibility.
     */
    public function isEmbeddedIframeRequest(Request $request): bool
    {
        return strtolower(trim((string) $request->headers->get('Sec-Fetch-Dest', ''))) === 'iframe';
    }

    /**
     * 403 notice served when an artifact is opened outside the sandbox iframe.
     * $openInAppUrl, when present, links the reader back into the application.
     */
    public function topLevelNavigationNotice(?string $openInAppUrl): Response
    {
        return response($this->topLevelNoticeHtml($openInAppUrl), 403, [
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'Content-Type' => 'text/html; charset=UTF-8',
            'Referrer-Policy' => 'no-referrer',
            'Vary' => 'Sec-Fetch-Dest',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
            'Content-Type' => 'text/html; charset=UTF-8',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Referrer-Policy' => 'no-referrer',
            // Host-only two-year HSTS. The artifact host is its own origin; like the
            // app host (config/app.php) it does not force includeSubDomains/preload,
            // which reach past this host and are hard to walk back.
            'Strict-Transport-Security' => 'max-age=63072000',
            'Vary' => 'Sec-Fetch-Dest',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'none'",
            'sandbox allow-scripts',
            "script-src 'unsafe-inline'",
            "style-src 'unsafe-inline'",
            'img-src data: blob:',
            'font-src data:',
            'media-src data: blob:',
            "connect-src 'none'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
            "frame-src 'none'",
            "fenced-frame-src 'none'",
            "child-src 'none'",
            "worker-src 'none'",
            "webrtc 'block'",
            'frame-ancestors ' . $this->frameAncestors(),
        ]);
    }

    private function frameAncestors(): string
    {
        $configured = $this->stringConfig('app.artifact_frame_ancestors');

        if ($configured === '') {
            $configured = $this->stringConfig('app.url');
        }

        $normalized = preg_replace('/\s+/', ' ', str_replace(',', ' ', trim($configured)));

        if (!is_string($normalized) || $normalized === '') {
            return "'none'";
        }

        return $normalized;
    }

    private function topLevelNoticeHtml(?string $openInAppUrl): string
    {
        $link = '';

        if ($openInAppUrl !== null && $openInAppUrl !== '') {
            $href = htmlspecialchars($openInAppUrl, ENT_QUOTES, 'UTF-8');
            $link = sprintf(' <a href="%s">Open it inside ArtifactFlow</a>.', $href);
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Artifact preview</title></head>'
            . '<body style="font-family:system-ui,-apple-system,sans-serif;margin:2rem;line-height:1.5;color:#18181f;">'
            . '<h1 style="font-size:1.15rem;">This artifact can only be viewed inside ArtifactFlow</h1>'
            . '<p>For security, artifact previews render only when embedded in the application. They cannot be opened as a standalone page.'
            . $link
            . '</p></body></html>';
    }

    /**
     * The application origin an artifact-host recovery notice should link back to. This host
     * serves the notice, but its own app.url is the cookieless artifact origin (compose
     * overwrites APP_URL so sessions and generated URLs scope to that host) -- linking from
     * app.url points the notice back at the artifact host itself and 404s. The origin
     * permitted to embed artifacts, carried in artifact_frame_ancestors, is the app origin
     * (the production boot gate and the doctor both require it to be exactly that single
     * origin), so it is the correct source. Fall back to app.url when frame-ancestors is unset
     * or is not a single absolute origin -- e.g. an app-role deployment where they coincide.
     *
     * The single frame-ancestors entry is parsed through OriginNormalizer -- the same parser
     * the boot gate and doctor use -- rather than matched ad hoc, so this cannot drift from
     * what they accept. In particular an uppercase scheme (HTTPS://...) boots because the gate
     * lowercases it, and must resolve here to the same origin instead of falling back to
     * app.url (the artifact host in an artifact-host runtime), which would 404.
     */
    public function appOrigin(): string
    {
        $frameAncestors = preg_split(
            '/[\s,]+/',
            $this->stringConfig('app.artifact_frame_ancestors'),
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        if (is_array($frameAncestors) && count($frameAncestors) === 1) {
            $origin = OriginNormalizer::tryParse($frameAncestors[0]);

            if ($origin !== null) {
                return $origin->compact();
            }
        }

        return $this->stringConfig('app.url');
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
