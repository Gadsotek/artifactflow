<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Application\PageCatalog\ArtifactPreviewConfiguration;
use App\Infrastructure\Security\OriginNormalizer;
use App\Models\PageVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use LogicException;

final readonly class ExternalArtifactPreviewUrl
{
    private const int MAX_EXPIRES_DIGITS = 12;

    private const string SIGNATURE_CONTEXT = "artifactflow.external-artifact-preview.v1\0";

    public function __construct(
        private ArtifactPreviewConfiguration $configuration,
    ) {
    }

    public function temporaryUrl(
        ExternalShareViewContext $context,
        PageVersion $version,
    ): string {
        $expiresAt = CarbonImmutable::now()
            ->addSeconds($this->configuration->ttlSeconds());

        if (
            $context->share->expires_at instanceof CarbonImmutable
            && $context->share->expires_at->lessThan($expiresAt)
        ) {
            $expiresAt = $context->share->expires_at;
        }

        $expires = $expiresAt->getTimestamp();
        $signature = $this->signature($context, $version->uid, $expires);

        return sprintf(
            '%s/external-artifact-previews/%s/sessions/%s/pages/%s/versions/%s?%s',
            $this->configuration->artifactOrigin(),
            rawurlencode($context->share->uid),
            rawurlencode($context->session->uid),
            rawurlencode($context->page->uid),
            rawurlencode($version->uid),
            http_build_query([
                'expires' => $expires,
                'signature' => $signature,
            ]),
        );
    }

    public function hasValidSignature(
        ExternalShareViewContext $context,
        string $versionUid,
        string|int|null $expires,
        ?string $signature,
    ): bool {
        if ($signature === null || $signature === '' || $expires === null) {
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

        if (
            (string) $expiresAt !== $expiresAsString
            || $expiresAt < CarbonImmutable::now()->getTimestamp()
            || (
                $context->share->expires_at instanceof CarbonImmutable
                && $expiresAt > $context->share->expires_at->getTimestamp()
            )
        ) {
            return false;
        }

        return hash_equals(
            $this->signature($context, $versionUid, $expiresAt),
            $signature,
        );
    }

    public function requestMatchesArtifactOrigin(Request $request): bool
    {
        $requestOrigin = OriginNormalizer::tryParse($request->getSchemeAndHttpHost());

        if ($requestOrigin === null) {
            throw new LogicException('External artifact request origin must include a scheme and host.');
        }

        return $requestOrigin->compact() === $this->configuration->artifactOrigin();
    }

    private function signature(
        ExternalShareViewContext $context,
        string $versionUid,
        int $expiresAt,
    ): string {
        return hash_hmac(
            'sha256',
            implode('|', [
                self::SIGNATURE_CONTEXT,
                $this->configuration->artifactOrigin(),
                $context->share->uid,
                $context->session->uid,
                $context->page->uid,
                $versionUid,
                (string) $expiresAt,
                (string) $context->page->preview_access_revision,
            ]),
            $this->configuration->signingKey(),
        );
    }
}
