<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PreparedPageContent
{
    public function __construct(
        public string $content,
        public ContentSecurityScan $scan,
        public string $storageFilename,
        public PageContentTextProjection $textProjection,
        public ?PdfProcessingResult $pdfProcessingResult = null,
        public ?XlsxProcessingResult $xlsxProcessingResult = null,
        public ?DocxProcessingResult $docxProcessingResult = null,
        public ?PreparedArtifactDerivative $derivative = null,
        public bool $requiresPrivateStaging = false,
        public ?StagedArtifactContent $stagedContent = null,
    ) {
    }

    public function withStagedContent(StagedArtifactContent $stagedContent): self
    {
        return new self(
            content: $this->content,
            scan: $this->scan,
            storageFilename: $this->storageFilename,
            textProjection: $this->textProjection,
            pdfProcessingResult: $this->pdfProcessingResult,
            xlsxProcessingResult: $this->xlsxProcessingResult,
            docxProcessingResult: $this->docxProcessingResult,
            derivative: $this->derivative,
            requiresPrivateStaging: $this->requiresPrivateStaging,
            stagedContent: $stagedContent,
        );
    }

    public function withStagedDerivative(PreparedArtifactDerivative $derivative): self
    {
        return new self(
            content: $this->content,
            scan: $this->scan,
            storageFilename: $this->storageFilename,
            textProjection: $this->textProjection,
            pdfProcessingResult: $this->pdfProcessingResult,
            xlsxProcessingResult: $this->xlsxProcessingResult,
            docxProcessingResult: $this->docxProcessingResult,
            derivative: $derivative,
            requiresPrivateStaging: $this->requiresPrivateStaging,
            stagedContent: $this->stagedContent,
        );
    }

    public function discardStaging(): void
    {
        $this->stagedContent?->discard();
        $this->derivative?->discardStaging();
    }

    public function byteSize(): int
    {
        return $this->originalByteSize() + ($this->derivative?->byteSize() ?? 0);
    }

    public function originalByteSize(): int
    {
        return $this->stagedContent->byteSize ?? strlen($this->content);
    }

    public function contentHash(): string
    {
        return $this->stagedContent->contentHash ?? hash('sha256', $this->content);
    }
}
