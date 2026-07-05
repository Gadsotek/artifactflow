<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;

final readonly class PageContentRules
{
    public function __construct(
        private InstallationLimitSettings $limits,
    ) {
    }

    public function normalize(PageType $type, string $content): string
    {
        if ($content === '') {
            throw new DomainRuleViolation('Page content must not be blank.');
        }

        if ($type === PageType::Markdown && trim($content) === '') {
            throw new DomainRuleViolation('Page content must not be blank.');
        }

        return $content;
    }

    public function ensureHtmlDocumentContent(PageType $type, string $content): void
    {
        if ($type !== PageType::HtmlArtifact) {
            return;
        }

        if (preg_match('/^\s*(?:<!doctype\s+html\b|<html\b)/i', $content) !== 1) {
            throw new DomainRuleViolation('HTML artifacts must start with an HTML document.');
        }
    }

    public function ensureFitsConfiguredLimit(PageType $type, string $content): void
    {
        $limit = $type === PageType::Markdown
            ? $this->limits->integer('pages.max_markdown_bytes')
            : $this->limits->integer('pages.max_html_bytes');

        if (strlen($content) > $limit) {
            throw new DomainRuleViolation('Page content exceeds the configured size limit.');
        }
    }
}
