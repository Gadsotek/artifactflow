<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\DocxArtifactLimits;
use App\Application\PageCatalog\DocxProcessorConfiguration;
use App\Application\PageCatalog\PdfProcessorConfiguration;
use App\Domain\DomainRuleViolation;

final readonly class McpDocxUpload
{
    public function __construct(
        private DocxProcessorConfiguration $configuration,
        private PdfProcessorConfiguration $pdfConfiguration,
        private DocxArtifactLimits $limits,
    ) {
    }

    public function decode(string $encoded): string
    {
        if (!$this->configuration->enabled() || !$this->pdfConfiguration->enabled()) {
            throw new DomainRuleViolation('Word document artifacts are disabled for this installation.');
        }
        $maximum = $this->limits->maxUploadBytes();
        if (strlen($encoded) > 4 * intdiv($maximum + 2, 3) || preg_match('/\s/', $encoded) === 1) {
            throw $this->invalid();
        }
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || $decoded === '' || strlen($decoded) > $maximum || base64_encode($decoded) !== $encoded) {
            throw $this->invalid();
        }

        return $decoded;
    }

    private function invalid(): DomainRuleViolation
    {
        return new DomainRuleViolation('Argument [docx_base64] must be canonical Base64 within the configured size limit.');
    }
}
