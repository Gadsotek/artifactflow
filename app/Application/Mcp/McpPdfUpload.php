<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\PdfArtifactLimits;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Domain\DomainRuleViolation;

/**
 * Strict JSON/Base64 adapter for PDF originals. It accepts no URL form and
 * never logs or reflects submitted encoded or decoded document data.
 */
final readonly class McpPdfUpload
{
    public function __construct(
        private PdfProcessorConfiguration $configuration,
        private PdfArtifactLimits $limits,
    ) {
    }

    public function decode(string $encoded): string
    {
        if (!$this->configuration->enabled()) {
            throw new DomainRuleViolation('PDF artifacts are disabled for this installation.');
        }

        $maxUploadBytes = $this->limits->maxUploadBytes();
        $maxEncodedBytes = 4 * intdiv($maxUploadBytes + 2, 3);

        if (strlen($encoded) > $maxEncodedBytes || preg_match('/\s/', $encoded) === 1) {
            throw $this->invalidBase64();
        }

        $decoded = base64_decode($encoded, true);

        if (
            !is_string($decoded)
            || $decoded === ''
            || strlen($decoded) > $maxUploadBytes
            || base64_encode($decoded) !== $encoded
        ) {
            throw $this->invalidBase64();
        }

        return $decoded;
    }

    private function invalidBase64(): DomainRuleViolation
    {
        return new DomainRuleViolation(
            'Argument [pdf_base64] must be canonical Base64 within the configured size limit.',
        );
    }
}
