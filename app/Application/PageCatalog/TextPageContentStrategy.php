<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use LogicException;

final readonly class TextPageContentStrategy implements PageContentStrategy
{
    public function __construct(
        private InstallationLimitSettings $limits,
        private PageContentScanner $scanner,
        private PageTextExtractor $textExtractor,
        private MarkdownRenderComplexity $markdownComplexity,
    ) {
    }

    public function supportedTypes(): array
    {
        return [PageType::Markdown, PageType::HtmlArtifact];
    }

    public function validateInput(PageType $type, string $content): void
    {
        $this->ensureSupported($type);

        if ($content === '' || ($type === PageType::Markdown && trim($content) === '')) {
            throw new DomainRuleViolation('Page content must not be blank.');
        }

        if (
            $type === PageType::HtmlArtifact
            && preg_match('/^\s*(?:<!doctype\s+html\b|<html\b)/i', $content) !== 1
        ) {
            throw new DomainRuleViolation('HTML artifacts must start with an HTML document.');
        }

        $limit = match ($type) {
            PageType::Markdown => $this->limits->integer('pages.max_markdown_bytes'),
            PageType::HtmlArtifact => $this->limits->integer('pages.max_html_bytes'),
            default => throw new LogicException('Unsupported textual page type.'),
        };

        if (strlen($content) > $limit) {
            throw new DomainRuleViolation('Page content exceeds the configured size limit.');
        }

        if ($type === PageType::Markdown) {
            $this->markdownComplexity->ensureSafe($content);
        }
    }

    public function validateSourceFilename(PageType $type, ?string $sourceFilename): void
    {
        $this->ensureSupported($type);

        if ($sourceFilename === null || $type === PageType::Markdown) {
            return;
        }

        if (strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION)) !== 'html') {
            throw new DomainRuleViolation('HTML artifact uploads must use a .html file.');
        }
    }

    public function prepare(
        PageType $type,
        string $content,
        string $actorUid,
        PageVersionSource $source,
    ): PreparedPageContent {
        $this->validateInput($type, $content);
        $scan = $this->scanner->scan($type, $content);

        if ($scan->hasBlockedFindings()) {
            throw new BlockedPageContentException($scan->blockedCodes());
        }

        return new PreparedPageContent(
            content: $content,
            scan: $scan,
            storageFilename: match ($type) {
                PageType::Markdown => 'source.md',
                PageType::HtmlArtifact => 'index.html',
                default => throw new LogicException('Unsupported textual page type.'),
            },
            textProjection: $this->textProjection($type, $content),
        );
    }

    public function textProjection(PageType $type, string $content): PageContentTextProjection
    {
        $this->ensureSupported($type);

        return new PageContentTextProjection(
            extractedText: $this->textExtractor->extract($type, $content),
            sourceText: $this->textExtractor->extractSource($type, $content),
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

        return true;
    }

    private function ensureSupported(PageType $type): void
    {
        if (!in_array($type, $this->supportedTypes(), true)) {
            throw new LogicException(sprintf(
                'Text page content strategy does not support [%s].',
                $type->value,
            ));
        }
    }
}
