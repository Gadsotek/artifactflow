<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use App\Application\Administration\InstallationLimitSettings;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Domain\PageCatalog\PageType;

final readonly class PassageExtractor
{
    public const int REVISION = 1;

    // Independently bound parser work even on installations allowing large sources.
    private const int MAX_SOURCE_BYTES = 2_097_152;

    public function __construct(
        private InstallationLimitSettings $limits,
    ) {
    }

    public function extract(PageType $type, string $source): PassageExtraction
    {
        if ($source === '' || !in_array($type, [PageType::Markdown, PageType::HtmlArtifact], true)) {
            return new PassageExtraction(ExtractionOutcome::Empty, []);
        }

        $limits = $this->limits->current();
        $formatLimit = $type === PageType::Markdown ? $limits->maxMarkdownBytes : $limits->maxHtmlBytes;
        if (strlen($source) > min($limits->artifactMaxBytes, $formatLimit)) {
            return new PassageExtraction(ExtractionOutcome::Limited, []);
        }

        $collector = new PassageCollector();
        $truncated = strlen($source) > self::MAX_SOURCE_BYTES;
        if ($truncated) {
            $collector->markLimited();
            // HTML needs the complete document to verify the DOM and table associations.
            if ($type === PageType::HtmlArtifact) {
                return $collector->result();
            }
            $prefix = substr($source, 0, self::MAX_SOURCE_BYTES);
            $lineBreak = max(strrpos($prefix, "\n"), strrpos($prefix, "\r"));
            $source = $lineBreak === false ? '' : substr($prefix, 0, $lineBreak + 1);
        }

        if (!PageContentEncoding::isStorable($source)) {
            $collector->markLimited();

            return $collector->result();
        }

        if ($type === PageType::Markdown) {
            (new MarkdownPassages())->collect($source, $collector, $truncated);
        } else {
            (new HtmlPassages())->collect($source, $collector);
        }

        return $collector->result();
    }
}
