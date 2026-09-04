<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use LogicException;

final readonly class XlsxPageContentStrategy implements PageContentStrategy
{
    public function __construct(
        private XlsxProcessorConfiguration $configuration,
        private XlsxArtifactLimits $limits,
        private XlsxProcessorClient $processor,
        private PageContentScanner $scanner,
    ) {
    }

    public function supportedTypes(): array
    {
        return [PageType::Xlsx];
    }

    public function validateInput(PageType $type, string $content): void
    {
        $this->ensureSupported($type);

        if (!$this->configuration->enabled()) {
            throw new DomainRuleViolation('XLSX artifacts are disabled for this installation.');
        }

        if ($content === '') {
            throw new DomainRuleViolation('Page content must not be blank.');
        }

        if (strlen($content) > $this->limits->maxUploadBytes()) {
            throw new DomainRuleViolation('XLSX exceeds the configured size limit.');
        }

        if (!str_starts_with($content, "PK\x03\x04")) {
            throw new DomainRuleViolation('XLSX content must be an Open XML workbook package.');
        }
    }

    public function validateSourceFilename(PageType $type, ?string $sourceFilename): void
    {
        $this->ensureSupported($type);

        if ($sourceFilename !== null && strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new DomainRuleViolation('Excel workbook uploads must use a .xlsx file.');
        }
    }

    public function prepare(
        PageType $type,
        string $content,
        string $actorUid,
        PageVersionSource $source,
    ): PreparedPageContent {
        $this->validateInput($type, $content);
        $result = $this->processor->project($content);

        if (strlen($result->manifestJson) > $this->limits->maxManifestBytes()) {
            throw new DomainRuleViolation('XLSX preview exceeds the configured artifact read limit.');
        }

        $scan = $this->scanner->scan($type, $result->searchText);

        if ($scan->hasBlockedFindings()) {
            throw new BlockedPageContentException($scan->blockedCodes());
        }

        return new PreparedPageContent(
            content: $content,
            scan: $scan,
            storageFilename: 'workbook.xlsx',
            textProjection: $this->projection($result),
            xlsxProcessingResult: $result,
            derivative: new PreparedArtifactDerivative(
                kind: ArtifactDerivativeKind::XlsxManifest,
                content: $result->manifestJson,
                storageFilename: 'manifest.json',
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
        $this->validateInput($type, $content);

        return $this->projection($this->processor->project($content));
    }

    private function projection(XlsxProcessingResult $result): PageContentTextProjection
    {
        return new PageContentTextProjection(
            extractedText: $result->searchText,
            sourceText: null,
        );
    }

    private function ensureSupported(PageType $type): void
    {
        if ($type !== PageType::Xlsx) {
            throw new LogicException(sprintf(
                'XLSX page content strategy does not support [%s].',
                $type->value,
            ));
        }
    }
}
