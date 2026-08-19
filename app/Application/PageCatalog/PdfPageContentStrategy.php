<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use LogicException;

final readonly class PdfPageContentStrategy implements PageContentStrategy
{
    public function __construct(
        private PdfProcessorConfiguration $configuration,
        private PdfArtifactLimits $limits,
        private PdfProcessorClient $processor,
        private PageContentScanner $scanner,
    ) {
    }

    public function supportedTypes(): array
    {
        return [PageType::Pdf];
    }

    public function validateInput(PageType $type, string $content): void
    {
        $this->ensureSupported($type);

        if (!$this->configuration->enabled()) {
            throw new DomainRuleViolation('PDF artifacts are disabled for this installation.');
        }

        if ($content === '') {
            throw new DomainRuleViolation('Page content must not be blank.');
        }

        if (strlen($content) > $this->limits->maxUploadBytes()) {
            throw new DomainRuleViolation('PDF exceeds the configured size limit.');
        }

        if (!str_starts_with($content, '%PDF-')) {
            throw new DomainRuleViolation('PDF content must start with a PDF document header.');
        }
    }

    public function validateSourceFilename(PageType $type, ?string $sourceFilename): void
    {
        $this->ensureSupported($type);

        if ($sourceFilename === null) {
            return;
        }

        if (strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new DomainRuleViolation('PDF uploads must use a .pdf file.');
        }
    }

    public function prepare(
        PageType $type,
        string $content,
        string $actorUid,
        PageVersionSource $source,
    ): PreparedPageContent {
        $this->validateInput($type, $content);
        $result = $this->processor->inspect($content);
        $scan = $this->scanner->scan($type, $result->text);

        if ($scan->hasBlockedFindings()) {
            throw new BlockedPageContentException($scan->blockedCodes());
        }

        return new PreparedPageContent(
            content: $content,
            scan: $scan,
            storageFilename: 'document.pdf',
            textProjection: $this->projection($result),
            pdfProcessingResult: $result,
            requiresPrivateStaging: true,
        );
    }

    public function textProjection(PageType $type, string $content): PageContentTextProjection
    {
        $this->validateInput($type, $content);

        return $this->projection($this->processor->inspect($content));
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

    private function projection(PdfProcessingResult $result): PageContentTextProjection
    {
        return new PageContentTextProjection(
            extractedText: $result->text,
            sourceText: null,
        );
    }

    private function ensureSupported(PageType $type): void
    {
        if ($type !== PageType::Pdf) {
            throw new LogicException(sprintf(
                'PDF page content strategy does not support [%s].',
                $type->value,
            ));
        }
    }
}
