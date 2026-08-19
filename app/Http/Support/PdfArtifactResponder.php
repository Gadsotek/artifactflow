<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\PageCatalog\PdfArtifactPurpose;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Response;

final readonly class PdfArtifactResponder
{
    public function __construct(
        private ArtifactFramePolicy $framePolicy,
    ) {
    }

    public function original(
        string $bytes,
        Page $page,
        PageVersion $version,
        PdfArtifactPurpose $purpose,
    ): Response {
        $disposition = $purpose === PdfArtifactPurpose::Download ? 'attachment' : 'inline';

        return $this->response($bytes, $page, $version, $disposition);
    }

    public function inline(string $bytes, Page $page, PageVersion $version): Response
    {
        return $this->response($bytes, $page, $version, 'inline');
    }

    private function response(
        string $bytes,
        Page $page,
        PageVersion $version,
        string $disposition,
    ): Response {
        $filename = sprintf(
            'artifactflow-%s-v%d.pdf',
            strtolower($page->uid),
            $version->version_number,
        );

        return response($bytes, 200, [
            'Accept-Ranges' => 'none',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, $filename),
            'Content-Length' => (string) strlen($bytes),
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "object-src 'none'",
                "base-uri 'none'",
                "form-action 'none'",
                'frame-ancestors ' . $this->framePolicy->frameAncestors(),
            ]),
            'Content-Type' => 'application/pdf',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Referrer-Policy' => 'no-referrer',
            'Strict-Transport-Security' => 'max-age=63072000',
            'X-Content-Type-Options' => 'nosniff',
            'X-DNS-Prefetch-Control' => 'off',
        ]);
    }
}
