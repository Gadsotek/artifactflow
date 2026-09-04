<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\PdfExtractionState;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use LogicException;

final readonly class DocxPageContentStrategy implements PageContentStrategy
{
    public function __construct(
        private DocxProcessorConfiguration $configuration,
        private PdfProcessorConfiguration $pdfConfiguration,
        private DocxArtifactLimits $limits,
        private DocxProcessorClient $docxProcessor,
        private PdfProcessorClient $pdfProcessor,
        private PageContentScanner $scanner,
    ) {
    }

    public function supportedTypes(): array
    {
        return [PageType::Docx];
    }

    public function validateInput(PageType $type, string $content): void
    {
        $this->ensureSupported($type);
        if (!$this->configuration->enabled() || !$this->pdfConfiguration->enabled()) {
            throw new DomainRuleViolation('Word document artifacts are disabled for this installation.');
        }
        if ($content === '') {
            throw new DomainRuleViolation('Page content must not be blank.');
        }
        if (strlen($content) > $this->limits->maxUploadBytes()) {
            throw new DomainRuleViolation('DOCX exceeds the configured size limit.');
        }
        if (!str_starts_with($content, "PK\x03\x04")) {
            throw new DomainRuleViolation('DOCX content must be an Open XML document package.');
        }
    }

    public function validateSourceFilename(PageType $type, ?string $sourceFilename): void
    {
        $this->ensureSupported($type);
        if ($sourceFilename !== null && strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION)) !== 'docx') {
            throw new DomainRuleViolation('Word document uploads must use a .docx file.');
        }
    }

    public function prepare(
        PageType $type,
        string $content,
        string $actorUid,
        PageVersionSource $source,
    ): PreparedPageContent {
        $this->validateInput($type, $content);
        $conversion = $this->docxProcessor->convert($content);

        if (strlen($conversion->pdfBytes) > $this->limits->maxPreviewBytes()) {
            throw new DomainRuleViolation('Word document preview exceeds the configured artifact read limit.');
        }

        $pdf = $this->pdfProcessor->inspectDocxPreview($conversion->pdfBytes);

        if ($pdf->extractionState !== PdfExtractionState::Indexed || trim($pdf->text) === '') {
            throw new DomainRuleViolation(
                'Word document preview must contain searchable selectable text; image-only or empty conversions are rejected.',
            );
        }

        $scan = $this->scanner->scan($type, $pdf->text);
        if ($scan->hasBlockedFindings()) {
            throw new BlockedPageContentException($scan->blockedCodes());
        }

        return new PreparedPageContent(
            content: $content,
            scan: $scan,
            storageFilename: 'document.docx',
            textProjection: new PageContentTextProjection(extractedText: $pdf->text, sourceText: null),
            docxProcessingResult: new DocxProcessingResult($conversion, $pdf),
            derivative: new PreparedArtifactDerivative(
                kind: ArtifactDerivativeKind::DocxPreviewPdf,
                content: $conversion->pdfBytes,
                storageFilename: 'preview.pdf',
            ),
            requiresPrivateStaging: true,
        );
    }

    public function requiresContentForTextProjection(PageType $type): bool
    {
        $this->ensureSupported($type);

        return true;
    }

    public function supportsSearchTextReindex(PageType $type): bool
    {
        $this->ensureSupported($type);

        return false;
    }

    public function textProjection(PageType $type, string $content): PageContentTextProjection
    {
        return $this->prepare($type, $content, '', PageVersionSource::Restore)->textProjection;
    }

    private function ensureSupported(PageType $type): void
    {
        if ($type !== PageType::Docx) {
            throw new LogicException(sprintf('DOCX page content strategy does not support [%s].', $type->value));
        }
    }
}
