<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentPreParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;

final class MarkdownPassages
{
    public function collect(string $source, PassageCollector $collector, bool $truncated): void
    {
        $lines = new MarkdownSourceLines($source, $collector);
        $environment = new Environment(['max_nesting_level' => PassageCollector::MAX_DEPTH]);
        $environment->addExtension(new MarkdownBlockRules());
        $environment->addEventListener(DocumentPreParsedEvent::class, static function (DocumentPreParsedEvent $event) use ($lines): void {
            $event->replaceMarkdown($lines);
        });

        $document = (new MarkdownParser($environment))->parse($source);
        $section = new MarkdownSection($source, $collector);
        foreach ($document->children() as $block) {
            if (!$collector->advance() || (($truncated || $lines->truncated) && $block === $document->lastChild())) {
                // Additional input could turn this unfinished paragraph into a Setext heading.
                break;
            }
            if (!$block instanceof AbstractBlock || ($first = $block->getStartLine()) === null || ($last = $block->getEndLine()) === null) {
                $collector->markLimited();
                continue;
            }
            while ($last > $first && $lines->blank($last)) {
                $last--;
            }
            [$start] = $lines->offsets($first);
            [, $end] = $lines->offsets($last);
            if ($block instanceof Heading) {
                $label = $block->firstChild();
                $title = $label instanceof Text ? (preg_replace('/\s+/u', ' ', $label->getLiteral()) ?? '') : '';
                $section->heading($block->getLevel(), trim($title), $start, $end, $first, $last);
            } else {
                $section->block($lines, $first, $last);
            }
        }

        $section->finish();
    }
}
