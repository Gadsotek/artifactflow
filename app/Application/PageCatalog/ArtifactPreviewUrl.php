<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\ArtifactPreviewPurpose;
use App\Infrastructure\Security\OriginNormalizer;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;

final class ArtifactPreviewUrl
{
    private const int MAX_EXPIRES_DIGITS = 12;

    public function __construct(
        private readonly ArtifactPreviewConfiguration $configuration,
    ) {
    }

    public function temporaryUrl(Page $page, PageVersion $version): string
    {
        return $this->temporaryUrlForPurpose($page, $version, ArtifactPreviewPurpose::Current);
    }

    public function temporaryHistoryUrl(Page $page, PageVersion $version): string
    {
        return $this->temporaryUrlForPurpose($page, $version, ArtifactPreviewPurpose::History);
    }

    public function hasValidSignature(
        Page $page,
        string $versionUid,
        string|int|null $expires,
        ?string $signature,
        ArtifactPreviewPurpose $purpose = ArtifactPreviewPurpose::Current,
    ): bool {
        if ($signature === null || $signature === '') {
            return false;
        }

        if ($expires === null) {
            return false;
        }

        $expiresAsString = (string) $expires;

        if (
            $expiresAsString === ''
            || strlen($expiresAsString) > self::MAX_EXPIRES_DIGITS
            || !ctype_digit($expiresAsString)
        ) {
            return false;
        }

        $expiresAt = (int) $expiresAsString;

        if ((string) $expiresAt !== $expiresAsString) {
            return false;
        }

        if ($expiresAt < Carbon::now()->getTimestamp()) {
            return false;
        }

        return hash_equals(
            $this->signature($page->uid, $versionUid, $expiresAt, $this->accessRevision($page), $purpose),
            $signature,
        );
    }

    private function temporaryUrlForPurpose(
        Page $page,
        PageVersion $version,
        ArtifactPreviewPurpose $purpose,
    ): string {
        $expiresAt = Carbon::now()->addSeconds($this->configuration->ttlSeconds())->getTimestamp();
        $signature = $this->signature(
            $page->uid,
            $version->uid,
            $expiresAt,
            $this->accessRevision($page),
            $purpose,
        );
        $queryParameters = [
            'expires' => $expiresAt,
            'signature' => $signature,
        ];

        if ($purpose === ArtifactPreviewPurpose::History) {
            $queryParameters['purpose'] = $purpose->value;
        }

        $query = http_build_query($queryParameters);

        return sprintf(
            '%s/artifact-previews/%s/versions/%s?%s',
            $this->configuration->artifactOrigin(),
            rawurlencode($page->uid),
            rawurlencode($version->uid),
            $query,
        );
    }

    /**
     * Absolute URL of the capability-protected draft-preview endpoint on the
     * artifact origin. It remains stateless and cookieless.
     */
    public function draftEndpointUrl(): string
    {
        return $this->configuration->artifactOrigin() . '/artifact-previews/draft';
    }

    public function requestMatchesArtifactOrigin(Request $request): bool
    {
        return $this->originFromUrl($request->getSchemeAndHttpHost()) === $this->configuration->artifactOrigin();
    }

    private function signature(
        string $pageUid,
        string $versionUid,
        int $expiresAt,
        int $accessRevision,
        ArtifactPreviewPurpose $purpose,
    ): string {
        $signatureParts = [
            $this->configuration->artifactOrigin(),
            $pageUid,
            $versionUid,
            (string) $expiresAt,
            (string) $accessRevision,
        ];

        // Preserve the current-preview signature shape for compatibility. Historical
        // access is a separate signed capability and can never be obtained by adding
        // a query parameter to an ordinary current-version URL.
        if ($purpose === ArtifactPreviewPurpose::History) {
            $signatureParts[] = $purpose->value;
        }

        return hash_hmac(
            'sha256',
            implode('|', $signatureParts),
            $this->configuration->signingKey(),
        );
    }

    private function accessRevision(Page $page): int
    {
        $revision = $page->getAttribute('preview_access_revision');

        return is_int($revision) || is_string($revision) ? (int) $revision : 0;
    }

    private function originFromUrl(string $url): string
    {
        $origin = OriginNormalizer::tryParse($url);

        if ($origin === null) {
            throw new LogicException('Artifact URL must include a scheme and host.');
        }

        return $origin->compact();
    }
}
