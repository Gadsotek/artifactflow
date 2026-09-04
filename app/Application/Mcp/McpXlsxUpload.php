<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\XlsxArtifactLimits;
use App\Application\PageCatalog\XlsxProcessorConfiguration;
use App\Domain\DomainRuleViolation;

/** Strict canonical-Base64 adapter. It accepts neither URLs nor data URLs. */
final readonly class McpXlsxUpload
{
    public function __construct(
        private XlsxProcessorConfiguration $configuration,
        private XlsxArtifactLimits $limits,
    ) {
    }

    public function decode(string $encoded): string
    {
        if (!$this->configuration->enabled()) {
            throw new DomainRuleViolation('XLSX artifacts are disabled for this installation.');
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
            'Argument [xlsx_base64] must be canonical Base64 within the configured size limit.',
        );
    }
}
