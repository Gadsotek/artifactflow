<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PdfArtifactPurpose;
use App\Infrastructure\Security\OriginNormalizer;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;

final readonly class PdfArtifactUrl
{
    private const int CLAIM_SCHEMA = 1;

    private const int MAX_TIMESTAMP_DIGITS = 12;

    private const int MAX_REVISION_DIGITS = 20;

    public function __construct(
        private ArtifactPreviewConfiguration $configuration,
        private PdfProcessorConfiguration $processorConfiguration,
    ) {
    }

    public function temporaryCurrentUrl(Page $page, PageVersion $version): string
    {
        return $this->temporaryUrl($page, $version, PdfArtifactPurpose::Current);
    }

    public function temporaryHistoryUrl(Page $page, PageVersion $version): string
    {
        return $this->temporaryUrl($page, $version, PdfArtifactPurpose::History);
    }

    public function temporaryDownloadUrl(Page $page, PageVersion $version): string
    {
        return $this->temporaryUrl($page, $version, PdfArtifactPurpose::Download);
    }

    public function validatedPurpose(Page $page, string $versionUid, Request $request): ?PdfArtifactPurpose
    {
        if (
            !$this->processorConfiguration->enabled()
            || !$this->requestMatchesArtifactOrigin($request)
        ) {
            return null;
        }

        $schema = $this->canonicalUnsignedInteger($this->queryString($request, 'v'), 1);
        $issuedAt = $this->canonicalUnsignedInteger(
            $this->queryString($request, 'issued'),
            self::MAX_TIMESTAMP_DIGITS,
        );
        $expiresAt = $this->canonicalUnsignedInteger(
            $this->queryString($request, 'expires'),
            self::MAX_TIMESTAMP_DIGITS,
        );
        $revision = $this->canonicalUnsignedInteger(
            $this->queryString($request, 'revision'),
            self::MAX_REVISION_DIGITS,
        );
        $signature = $this->queryString($request, 'signature');
        $purpose = PdfArtifactPurpose::tryFrom($this->queryString($request, 'purpose') ?? '');

        if (
            $schema !== self::CLAIM_SCHEMA
            || $issuedAt === null
            || $expiresAt === null
            || $revision === null
            || !$purpose instanceof PdfArtifactPurpose
            || $signature === null
            || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
        ) {
            return null;
        }

        $now = Carbon::now()->getTimestamp();
        if (
            $issuedAt > $now
            || $expiresAt < $now
            || $expiresAt < $issuedAt
            || ($expiresAt - $issuedAt) > $this->configuration->ttlSeconds()
            || $revision !== $page->preview_access_revision
        ) {
            return null;
        }

        $expected = $this->signature(
            $page->uid,
            $versionUid,
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
        PdfArtifactPurpose $purpose,
    ): string {
        if (!$this->processorConfiguration->enabled()) {
            throw new LogicException('PDF artifact delivery is disabled for this installation.');
        }

        $issuedAt = Carbon::now()->getTimestamp();
        $expiresAt = $issuedAt + $this->configuration->ttlSeconds();
        $revision = $this->accessRevision($page);
        $query = http_build_query([
            'v' => self::CLAIM_SCHEMA,
            'purpose' => $purpose->value,
            'issued' => $issuedAt,
            'expires' => $expiresAt,
            'revision' => $revision,
            'signature' => $this->signature(
                $page->uid,
                $version->uid,
                $purpose,
                $issuedAt,
                $expiresAt,
                $revision,
            ),
        ]);

        return sprintf(
            '%s/pdf-artifacts/%s/versions/%s?%s',
            $this->configuration->artifactOrigin(),
            rawurlencode($page->uid),
            rawurlencode($version->uid),
            $query,
        );
    }

    private function signature(
        string $pageUid,
        string $versionUid,
        PdfArtifactPurpose $purpose,
        int $issuedAt,
        int $expiresAt,
        int $revision,
    ): string {
        return hash_hmac('sha256', implode('|', [
            'artifactflow-pdf-artifact-v1',
            $this->configuration->artifactOrigin(),
            $purpose->value,
            $pageUid,
            $versionUid,
            (string) $issuedAt,
            (string) $expiresAt,
            (string) $revision,
        ]), $this->configuration->signingKey());
    }

    private function requestMatchesArtifactOrigin(Request $request): bool
    {
        $origin = OriginNormalizer::tryParse($request->getSchemeAndHttpHost());

        if ($origin === null) {
            throw new LogicException('Artifact request origin must include a scheme and host.');
        }

        return $origin->compact() === $this->configuration->artifactOrigin();
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    private function canonicalUnsignedInteger(?string $value, int $maximumDigits): ?int
    {
        if ($value === null || $value === '' || strlen($value) > $maximumDigits || !ctype_digit($value)) {
            return null;
        }

        $integer = (int) $value;

        return (string) $integer === $value ? $integer : null;
    }

    private function accessRevision(Page $page): int
    {
        $revision = $page->getAttribute('preview_access_revision');

        return is_int($revision) || is_string($revision) ? (int) $revision : 0;
    }
}
