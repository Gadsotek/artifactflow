<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

/**
 * Bounds cumulative alternate-tokenizer work to a linear multiple of the
 * original document. Each recursive pass consumes the bytes it will rescan.
 */
final class ArtifactPreviewRewriteBudget
{
    private int $remainingBytes;

    public function __construct(int $sourceBytes)
    {
        $this->remainingBytes = max(1, $sourceBytes) * 2;
    }

    public function consume(int $bytes): void
    {
        $this->remainingBytes -= $bytes;

        if ($this->remainingBytes < 0) {
            throw new ArtifactPreviewComplexityExceeded(
                'Artifact preview alternate parsing exceeds the safe rendering work limit.',
            );
        }
    }
}
