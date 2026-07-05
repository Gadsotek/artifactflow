<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Page;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class MarkdownWikiLinkResolver
{
    private const int MAX_UNIQUE_TARGETS = 100;

    public function __construct(
        private PageAccess $access,
        private UrlGenerator $urls,
    ) {
    }

    public function resolve(User $actor, Page $sourcePage, string $html): string
    {
        if (!str_contains($html, '[[')) {
            return $html;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                sprintf(
                    // <meta charset> keeps libxml from decoding UTF-8 as ISO-8859-1 and
                    // double-encoding multibyte prose (café -> cafÃ©) around wiki links.
                    '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="artifactflow-markdown-root">%s</div></body></html>',
                    $html,
                ),
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            $root = $document->getElementById('artifactflow-markdown-root');

            if (!$root instanceof DOMElement) {
                return $html;
            }

            $xpath = new DOMXPath($document);
            $nodes = $xpath->query('//text()[contains(., "[[")]');

            if ($nodes === false) {
                return $html;
            }

            $textNodes = [];

            foreach ($nodes as $node) {
                if ($node instanceof DOMText) {
                    $textNodes[] = $node;
                }
            }

            // Resolve every distinct target in a single query up front, rather than
            // one query per unique [[target]] inside the replacement loop (which let a
            // page author force up to MAX_UNIQUE_TARGETS DB round-trips per render).
            $targetCache = $this->resolveTargets(
                $actor,
                $sourcePage,
                $this->collectUniqueTargets($textNodes),
            );

            foreach ($textNodes as $textNode) {
                if ($this->isInsideExcludedElement($textNode)) {
                    continue;
                }

                $this->replaceWikiLinks($document, $textNode, $targetCache);
            }

            return $this->innerHtml($root);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param list<DOMText> $textNodes
     *
     * @return list<string> distinct normalized (lower-cased) targets, bounded
     */
    private function collectUniqueTargets(array $textNodes): array
    {
        $normalized = [];

        foreach ($textNodes as $textNode) {
            if ($this->isInsideExcludedElement($textNode)) {
                continue;
            }

            foreach ($this->wikiLinkTargetsIn($textNode->nodeValue ?? '') as $targetName) {
                $key = mb_strtolower($targetName);

                if (!array_key_exists($key, $normalized)) {
                    if (count($normalized) >= self::MAX_UNIQUE_TARGETS) {
                        break 2;
                    }

                    $normalized[$key] = true;
                }
            }
        }

        return array_keys($normalized);
    }

    /**
     * @return list<string>
     */
    private function wikiLinkTargetsIn(string $text): array
    {
        $parts = preg_split(
            '/(\[\[[^\[\]\r\n]{1,255}\]\])/u',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if (!is_array($parts)) {
            return [];
        }

        $targets = [];

        foreach ($parts as $part) {
            $target = $this->wikiLinkTarget($part);

            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * @param array<string, Page|null> $targetCache
     */
    private function replaceWikiLinks(
        DOMDocument $document,
        DOMText $textNode,
        array $targetCache,
    ): void {
        $nodeValue = $textNode->nodeValue ?? '';
        $parts = preg_split(
            '/(\[\[[^\[\]\r\n]{1,255}\]\])/u',
            $nodeValue,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if (!is_array($parts)) {
            return;
        }

        $fragment = $document->createDocumentFragment();

        foreach ($parts as $part) {
            $targetName = $this->wikiLinkTarget($part);
            $targetPage = $targetName === null ? null : ($targetCache[mb_strtolower($targetName)] ?? null);

            if ($targetName === null || !$targetPage instanceof Page) {
                $fragment->appendChild($document->createTextNode($part));

                continue;
            }

            $link = $document->createElement('a');
            $link->setAttribute('href', $this->urls->route('pages.show', $targetPage, false));
            $link->appendChild($document->createTextNode($targetName));
            $fragment->appendChild($link);
        }

        $textNode->parentNode?->replaceChild($fragment, $textNode);
    }

    private function wikiLinkTarget(string $part): ?string
    {
        $matches = [];

        if (preg_match('/^\[\[([^\[\]\r\n]{1,255})\]\]$/u', $part, $matches) !== 1) {
            return null;
        }

        $targetName = trim($matches[1]);

        return $targetName === '' ? null : $targetName;
    }

    /**
     * @param list<string> $normalizedTargets
     *
     * @return array<string, Page|null>
     */
    private function resolveTargets(User $actor, Page $sourcePage, array $normalizedTargets): array
    {
        /** @var array<string, Page|null> $cache */
        $cache = array_fill_keys($normalizedTargets, null);

        if ($normalizedTargets === []) {
            return $cache;
        }

        $candidates = Page::query()
            ->where('workspace_uid', $sourcePage->workspace_uid)
            ->where(function (Builder $query) use ($normalizedTargets): void {
                $query->whereIn(DB::raw('LOWER(title)'), $normalizedTargets)
                    ->orWhereIn(DB::raw('LOWER(slug)'), $normalizedTargets);
            })
            // Eager-load grants so the per-candidate canView() below does not
            // issue one PageAccessGrant query per wiki-link target on render.
            ->with('accessGrants')
            ->limit(self::MAX_UNIQUE_TARGETS * 21)
            ->get();

        /** @var array<string, list<Page>> $titleMatches */
        $titleMatches = [];
        /** @var array<string, list<Page>> $slugMatches */
        $slugMatches = [];

        foreach ($candidates as $candidate) {
            if (!$this->access->canView($actor, $candidate)) {
                continue;
            }

            $title = mb_strtolower($candidate->title);
            $slug = mb_strtolower($candidate->slug);

            if (array_key_exists($title, $cache)) {
                $titleMatches[$title][] = $candidate;
            }

            if (array_key_exists($slug, $cache)) {
                $slugMatches[$slug][] = $candidate;
            }
        }

        foreach ($normalizedTargets as $target) {
            $titles = $titleMatches[$target] ?? [];
            $slugs = $slugMatches[$target] ?? [];

            // A target resolves only on an unambiguous single title match, else a
            // single slug match — same rule as before, now decided per target.
            $cache[$target] = count($titles) === 1
                ? $titles[0]
                : (count($slugs) === 1 ? $slugs[0] : null);
        }

        return $cache;
    }

    private function isInsideExcludedElement(DOMText $textNode): bool
    {
        $ancestor = $textNode->parentNode;

        while ($ancestor instanceof DOMElement) {
            if (in_array(strtolower($ancestor->tagName), ['a', 'code', 'pre', 'textarea'], true)) {
                return true;
            }

            $ancestor = $ancestor->parentNode;
        }

        return false;
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $childNode) {
            $html .= $node->ownerDocument?->saveHTML($childNode) ?: '';
        }

        return $html;
    }
}
