<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\DocumentOriginalPurpose;
use App\Infrastructure\Security\OriginNormalizer;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;

final readonly class DocxPreviewUrl
{
    private const int CLAIM_SCHEMA = 1;

    public function __construct(
        private ArtifactPreviewConfiguration $configuration,
        private DocxProcessorConfiguration $docxConfiguration,
        private PdfProcessorConfiguration $pdfConfiguration,
    ) {
    }

    public function temporaryCurrentUrl(Page $page, PageVersion $version): string
    {
        return $this->temporaryUrl($page, $version, DocumentOriginalPurpose::Current);
    }

    public function temporaryHistoryUrl(Page $page, PageVersion $version): string
    {
        return $this->temporaryUrl($page, $version, DocumentOriginalPurpose::History);
    }

    public function validatedPurpose(Page $page, string $versionUid, Request $request): ?DocumentOriginalPurpose
    {
        if (!$this->enabled() || !$this->matchesOrigin($request)) {
            return null;
        }
        $schema = $this->canonical($this->query($request, 'v'), 1);
        $issued = $this->canonical($this->query($request, 'issued'), 12);
        $expires = $this->canonical($this->query($request, 'expires'), 12);
        $revision = $this->canonical($this->query($request, 'revision'), 20);
        $purpose = DocumentOriginalPurpose::tryFrom($this->query($request, 'purpose') ?? '');
        $signature = $this->query($request, 'signature');
        if (
            $schema !== self::CLAIM_SCHEMA
            || $issued === null
            || $expires === null
            || $revision === null
            || !$purpose instanceof DocumentOriginalPurpose
            || $signature === null
            || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1
        ) {
            return null;
        }
        $now = Carbon::now()->getTimestamp();
        if (
            $issued > $now
            || $expires < $now
            || $expires < $issued
            || $expires - $issued > $this->configuration->ttlSeconds()
            || $revision !== $page->preview_access_revision
        ) {
            return null;
        }

        return hash_equals(
            $this->signature($page->uid, $versionUid, $purpose, $issued, $expires, $revision),
            $signature,
        ) ? $purpose : null;
    }

    private function temporaryUrl(Page $page, PageVersion $version, DocumentOriginalPurpose $purpose): string
    {
        if (!$this->enabled()) {
            throw new LogicException('Word document preview is disabled for this installation.');
        }
        $issued = Carbon::now()->getTimestamp();
        $expires = $issued + $this->configuration->ttlSeconds();
        $revision = $page->preview_access_revision;
        $query = http_build_query([
            'v' => self::CLAIM_SCHEMA,
            'purpose' => $purpose->value,
            'issued' => $issued,
            'expires' => $expires,
            'revision' => $revision,
            'signature' => $this->signature($page->uid, $version->uid, $purpose, $issued, $expires, $revision),
        ]);

        return sprintf(
            '%s/docx-previews/%s/versions/%s?%s',
            $this->configuration->artifactOrigin(),
            rawurlencode($page->uid),
            rawurlencode($version->uid),
            $query,
        );
    }

    private function signature(
        string $pageUid,
        string $versionUid,
        DocumentOriginalPurpose $purpose,
        int $issued,
        int $expires,
        int $revision,
    ): string {
        return hash_hmac('sha256', implode('|', [
            'artifactflow-docx-preview-v1',
            $this->configuration->artifactOrigin(),
            $purpose->value,
            $pageUid,
            $versionUid,
            (string) $issued,
            (string) $expires,
            (string) $revision,
        ]), $this->configuration->signingKey());
    }

    private function enabled(): bool
    {
        return $this->docxConfiguration->enabled() && $this->pdfConfiguration->enabled();
    }

    private function matchesOrigin(Request $request): bool
    {
        $origin = OriginNormalizer::tryParse($request->getSchemeAndHttpHost());

        return $origin !== null && $origin->compact() === $this->configuration->artifactOrigin();
    }

    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    private function canonical(?string $value, int $digits): ?int
    {
        if ($value === null || $value === '' || strlen($value) > $digits || !ctype_digit($value)) {
            return null;
        }
        $integer = (int) $value;

        return (string) $integer === $value ? $integer : null;
    }
}
