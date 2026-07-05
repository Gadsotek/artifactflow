<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\ArtifactPreviewPurpose;
use App\Infrastructure\Security\OriginNormalizer;
use App\Infrastructure\Security\SecretStrength;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;

final class ArtifactPreviewUrl
{
    private const int MAX_EXPIRES_DIGITS = 12;

    private const int MAX_TTL_SECONDS = 60;

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
        $expiresAt = Carbon::now()->addSeconds($this->ttlSeconds())->getTimestamp();
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
            $this->artifactOrigin(),
            rawurlencode($page->uid),
            rawurlencode($version->uid),
            $query,
        );
    }

    /**
     * Absolute URL of the stateless draft-preview endpoint on the artifact origin.
     * Unsigned by design: it renders unsaved, caller-supplied HTML with no stored
     * record to authorize against, and the opaque sandbox is the boundary.
     */
    public function draftEndpointUrl(): string
    {
        return $this->artifactOrigin() . '/artifact-previews/draft';
    }

    public function requestMatchesArtifactOrigin(Request $request): bool
    {
        return $this->originFromUrl($request->getSchemeAndHttpHost()) === $this->artifactOrigin();
    }

    private function signature(
        string $pageUid,
        string $versionUid,
        int $expiresAt,
        int $accessRevision,
        ArtifactPreviewPurpose $purpose,
    ): string {
        $signatureParts = [$this->artifactOrigin(), $pageUid, $versionUid, (string) $expiresAt, (string) $accessRevision];

        // Preserve the current-preview signature shape for compatibility. Historical
        // access is a separate signed capability and can never be obtained by adding
        // a query parameter to an ordinary current-version URL.
        if ($purpose === ArtifactPreviewPurpose::History) {
            $signatureParts[] = $purpose->value;
        }

        return hash_hmac(
            'sha256',
            implode('|', $signatureParts),
            $this->signingKey(),
        );
    }

    private function accessRevision(Page $page): int
    {
        $revision = $page->getAttribute('preview_access_revision');

        return is_int($revision) || is_string($revision) ? (int) $revision : 0;
    }

    private function artifactOrigin(): string
    {
        return $this->originFromUrl($this->stringConfig('app.artifact_url'));
    }

    private function originFromUrl(string $url): string
    {
        $origin = OriginNormalizer::tryParse($url);

        if ($origin === null) {
            throw new LogicException('Artifact URL must include a scheme and host.');
        }

        return $origin->compact();
    }

    private function ttlSeconds(): int
    {
        $configuredTtl = config('app.artifact_preview_url_ttl_seconds', self::MAX_TTL_SECONDS);
        $ttlSeconds = is_int($configuredTtl) || is_string($configuredTtl)
            ? (int) $configuredTtl
            : self::MAX_TTL_SECONDS;

        return min(self::MAX_TTL_SECONDS, max(1, $ttlSeconds));
    }

    private function signingKey(): string
    {
        $key = $this->stringConfig('app.artifact_url_signing_key');

        if ($key === '') {
            throw new LogicException('Artifact preview signing key is not configured.');
        }

        $normalized = SecretStrength::isStrong($key) ? SecretStrength::normalized($key) : null;

        if ($normalized === null) {
            throw new LogicException('Artifact preview signing key must be a non-placeholder 32-byte secret.');
        }

        return $normalized;
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
