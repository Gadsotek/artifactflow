<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Response;
use InvalidArgumentException;

final readonly class DocumentOriginalResponder
{
    public function attachment(string $bytes, Page $page, PageVersion $version): Response
    {
        [$extension, $mediaType] = match ($page->type) {
            PageType::Xlsx => [
                'xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            PageType::Docx => [
                'docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            default => throw new InvalidArgumentException('Unsupported document original page type.'),
        };
        $filename = sprintf(
            'artifactflow-%s-v%d.%s',
            strtolower($page->uid),
            $version->version_number,
            $extension,
        );

        return response($bytes, 200, [
            'Accept-Ranges' => 'none',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length' => (string) strlen($bytes),
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'",
            'Content-Type' => $mediaType,
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Referrer-Policy' => 'no-referrer',
            'Strict-Transport-Security' => 'max-age=63072000',
            'X-Content-Type-Options' => 'nosniff',
            'X-DNS-Prefetch-Control' => 'off',
        ]);
    }
}
