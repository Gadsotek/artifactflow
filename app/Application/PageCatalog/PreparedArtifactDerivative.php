<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\ArtifactDerivativeKind;

final readonly class PreparedArtifactDerivative
{
    public function __construct(
        public ArtifactDerivativeKind $kind,
        public string $content,
        public string $storageFilename,
        public ?StagedArtifactContent $stagedContent = null,
    ) {
    }

    public function withStagedContent(StagedArtifactContent $stagedContent): self
    {
        return new self(
            kind: $this->kind,
            content: $this->content,
            storageFilename: $this->storageFilename,
            stagedContent: $stagedContent,
        );
    }

    public function discardStaging(): void
    {
        $this->stagedContent?->discard();
    }

    public function byteSize(): int
    {
        return $this->stagedContent->byteSize ?? strlen($this->content);
    }

    public function contentHash(): string
    {
        return $this->stagedContent->contentHash ?? hash('sha256', $this->content);
    }
}
