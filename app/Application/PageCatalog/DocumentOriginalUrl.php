<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\DocumentOriginalPurpose;
use App\Domain\PageCatalog\PageType;
use App\Infrastructure\Security\OriginNormalizer;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;

final readonly class DocumentOriginalUrl
{
    private const int CLAIM_SCHEMA = 1;

    public function __construct(
        private ArtifactPreviewConfiguration $configuration,
        private XlsxProcessorConfiguration $xlsxConfiguration,
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
        if (!$this->enabledFor($page->type) || !$this->requestMatchesArtifactOrigin($request)) {
            return null;
        }

        $schema = $this->canonicalInteger($this->query($request, 'v'), 1);
        $issuedAt = $this->canonicalInteger($this->query($request, 'issued'), 12);
        $expiresAt = $this->canonicalInteger($this->query($request, 'expires'), 12);
        $revision = $this->canonicalInteger($this->query($request, 'revision'), 20);
        $purpose = DocumentOriginalPurpose::tryFrom($this->query($request, 'purpose') ?? '');
        $signature = $this->query($request, 'signature');

        if (
            $schema !== self::CLAIM_SCHEMA
            || $issuedAt === null
            || $expiresAt === null
            || $revision === null
            || !$purpose instanceof DocumentOriginalPurpose
            || $signature === null
            || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1
        ) {
            return null;
        }

        $now = Carbon::now()->getTimestamp();

        if (
            $issuedAt > $now
            || $expiresAt < $now
            || $expiresAt < $issuedAt
            || $expiresAt - $issuedAt > $this->configuration->ttlSeconds()
            || $revision !== $page->preview_access_revision
        ) {
            return null;
        }

        $expected = $this->signature(
            $page->uid,
            $versionUid,
            $page->type,
            $purpose,
            $issuedAt,
            $expiresAt,
            $revision,
        );

        return hash_equals($expected, $signature) ? $purpose : null;
    }

    private function temporaryUrl(
        Page $page,
        PageVersion $version,
        DocumentOriginalPurpose $purpose,
    ): string {
        if (!$this->enabledFor($page->type)) {
            throw new LogicException('Document original delivery is disabled for this page type.');
        }

        $issuedAt = Carbon::now()->getTimestamp();
        $expiresAt = $issuedAt + $this->configuration->ttlSeconds();
        $revision = $page->preview_access_revision;
        $query = http_build_query([
            'v' => self::CLAIM_SCHEMA,
            'purpose' => $purpose->value,
            'issued' => $issuedAt,
            'expires' => $expiresAt,
            'revision' => $revision,
            'signature' => $this->signature(
                $page->uid,
                $version->uid,
                $page->type,
                $purpose,
                $issuedAt,
                $expiresAt,
                $revision,
            ),
        ]);

        return sprintf(
            '%s/document-originals/%s/versions/%s?%s',
            $this->configuration->artifactOrigin(),
            rawurlencode($page->uid),
            rawurlencode($version->uid),
            $query,
        );
    }

    private function signature(
        string $pageUid,
        string $versionUid,
        PageType $type,
        DocumentOriginalPurpose $purpose,
        int $issuedAt,
        int $expiresAt,
        int $revision,
    ): string {
        return hash_hmac('sha256', implode('|', [
            'artifactflow-document-original-v1',
            $this->configuration->artifactOrigin(),
            $type->value,
            $purpose->value,
            $pageUid,
            $versionUid,
            (string) $issuedAt,
            (string) $expiresAt,
            (string) $revision,
        ]), $this->configuration->signingKey());
    }

    public function enabledFor(PageType $type): bool
    {
        return match ($type) {
            PageType::Xlsx => $this->xlsxConfiguration->enabled(),
            PageType::Docx => $this->docxConfiguration->enabled() && $this->pdfConfiguration->enabled(),
            default => false,
        };
    }

    private function requestMatchesArtifactOrigin(Request $request): bool
    {
        $origin = OriginNormalizer::tryParse($request->getSchemeAndHttpHost());

        return $origin !== null && $origin->compact() === $this->configuration->artifactOrigin();
    }

    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    private function canonicalInteger(?string $value, int $maxDigits): ?int
    {
        if ($value === null || $value === '' || strlen($value) > $maxDigits || !ctype_digit($value)) {
            return null;
        }

        $integer = (int) $value;

        return (string) $integer === $value ? $integer : null;
    }
}
