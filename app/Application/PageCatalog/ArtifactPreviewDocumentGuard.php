<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use RuntimeException;

final class ArtifactPreviewDocumentGuard
{
    /**
     * Canonical guard body injected by the shared sandbox responder into both
     * saved artifacts and pre-save draft previews. Edit the guard logic there,
     * never inline here.
     */
    private const string GUARD_SOURCE = 'js/artifact-preview-guard.js';

    /**
     * @var list<string>
     */
    private const array RESOURCE_HINT_RELS = ['dns-prefetch', 'preconnect', 'prefetch', 'prerender'];

    /**
     * @var list<string>
     */
    private const array NESTED_BROWSING_CONTEXT_TAGS = ['iframe', 'frame', 'fencedframe', 'portal'];

    /**
     * Elements whose contents the HTML tokenizer does not interpret as ordinary
     * start tags. Scanning their bytes as markup corrupts scripts and user text.
     *
     * @var list<string>
     */
    private const array RAW_TEXT_TAGS = [
        'script',
        'style',
        'xmp',
        'iframe',
        'noembed',
        'noframes',
        'textarea',
        'title',
    ];

    /**
     * RAW_TEXT_TAGS whose content is raw text in the HTML namespace but is parsed
     * as ordinary markup inside SVG/MathML foreign content (they are not foreign
     * raw-text elements there). Inside such a subtree the guard must keep scanning
     * their content so a nested browsing context is still recognized -- e.g.
     * `<svg><title><iframe srcdoc=...>`, where SVG `<title>` is an HTML integration
     * point and the browser makes the iframe live. `script`/`style` stay raw text in
     * every namespace, so they are deliberately excluded.
     *
     * @var list<string>
     */
    private const array FOREIGN_PARSED_TEXT_TAGS = ['title', 'textarea', 'xmp', 'noembed', 'noframes'];

    private static ?string $guardBody = null;

    /**
     * Best-effort, defense-in-depth hardening layered on top of the real boundary
     * (opaque-origin sandbox iframe + strict CSP with default-src/connect-src
     * 'none'). The response-time token-aware neutralization and in-page API patches below
     * close known browser gaps around nested browsing contexts and trim
     * self-navigation/prefetch-exfil noise, but must never justify relaxing the
     * sandbox, origin split, or CSP.
     */
    public function harden(string $html, bool $recoveryEnabled = false): string
    {
        $html = $this->rewriteDangerousMarkup($html);
        $guard = $this->guardScript($recoveryEnabled);
        $withDoctypeGuard = $this->injectAfterPattern($html, '/^\s*<!doctype\s+html\b[^>]*>/i', $guard);

        return $withDoctypeGuard ?? $guard . $html;
    }

    private function guardScript(bool $recoveryEnabled): string
    {
        if (self::$guardBody === null) {
            $path = resource_path(self::GUARD_SOURCE);
            $body = is_file($path) ? file_get_contents($path) : false;

            if (!is_string($body) || trim($body) === '') {
                throw new RuntimeException('Artifact preview guard source is missing.');
            }

            self::$guardBody = $body;
        }

        $recoveryAttribute = $recoveryEnabled ? ' data-artifactflow-preview-recovery' : '';

        return "<script data-artifactflow-preview-guard{$recoveryAttribute}>\n" . self::$guardBody . "\n</script>";
    }

    private function rewriteDangerousMarkup(string $html): string
    {
        $length = strlen($html);
        $offset = 0;
        $result = '';
        $rawTextTag = null;
        $neutralizedContainers = [];
        // Depth of the current SVG/MathML foreign-content subtree. Inside it, the
        // context-sensitive raw-text tags above are parsed as markup, not skipped.
        $foreignDepth = 0;

        while ($offset < $length) {
            if ($rawTextTag !== null) {
                $closingOffset = $this->findRawTextClosingTag($html, $rawTextTag, $offset);

                if ($closingOffset === null) {
                    // Unterminated raw-text element: everything to EOF is its
                    // interior. A neutralized iframe's interior was relocated into a
                    // parsed template, so it must be escaped here too -- otherwise an
                    // embedded </template> closes the wrapper and following bytes
                    // (e.g. <iframe srcdoc>) parse as a live nested browsing context.
                    return $result . $this->relocatedRawText(substr($html, $offset), $rawTextTag);
                }

                $result .= $this->relocatedRawText(substr($html, $offset, $closingOffset - $offset), $rawTextTag);
                $closingTag = $this->tagAt($html, $closingOffset);

                if ($closingTag === null) {
                    $result .= '<';
                    $offset = $closingOffset + 1;
                    continue;
                }

                $tagText = substr($html, $closingOffset, $closingTag['end'] - $closingOffset + 1);
                $result .= in_array($rawTextTag, self::NESTED_BROWSING_CONTEXT_TAGS, true)
                    ? '</template>'
                    : $tagText;
                $offset = $closingTag['end'] + 1;
                $rawTextTag = null;
                continue;
            }

            $tagOffset = strpos($html, '<', $offset);

            if ($tagOffset === false) {
                return $result . substr($html, $offset);
            }

            $result .= substr($html, $offset, $tagOffset - $offset);

            if (substr_compare($html, '<!--', $tagOffset, 4) === 0) {
                $commentEnd = strpos($html, '-->', $tagOffset + 4);

                if ($commentEnd === false) {
                    return $result . substr($html, $tagOffset);
                }

                $commentLength = $commentEnd + 3 - $tagOffset;
                $result .= substr($html, $tagOffset, $commentLength);
                $offset = $commentEnd + 3;
                continue;
            }

            $nextCharacter = $html[$tagOffset + 1] ?? '';

            if ($nextCharacter === '!' || $nextCharacter === '?') {
                $declarationEnd = $this->tagEnd($html, $tagOffset);

                if ($declarationEnd === null) {
                    return $result . substr($html, $tagOffset);
                }

                $result .= substr($html, $tagOffset, $declarationEnd - $tagOffset + 1);
                $offset = $declarationEnd + 1;
                continue;
            }

            $tag = $this->tagAt($html, $tagOffset);

            if ($tag === null) {
                $result .= '<';
                $offset = $tagOffset + 1;
                continue;
            }

            $tagText = substr($html, $tagOffset, $tag['end'] - $tagOffset + 1);
            $offset = $tag['end'] + 1;

            if ($tag['closing']) {
                if ($foreignDepth > 0 && ($tag['name'] === 'svg' || $tag['name'] === 'math')) {
                    $foreignDepth--;
                }

                $openContainer = end($neutralizedContainers);

                if ($openContainer === $tag['name']) {
                    array_pop($neutralizedContainers);
                    $result .= '</template>';
                } else {
                    $result .= $tagText;
                }

                continue;
            }

            if (
                ($tag['name'] === 'meta' && $this->isRefreshMetaTag($tagText))
                || ($tag['name'] === 'link' && $this->isResourceHintLink($tagText))
            ) {
                continue;
            }

            if (in_array($tag['name'], self::NESTED_BROWSING_CONTEXT_TAGS, true)) {
                // Keep attributes and fallback bytes inspectable, but make the
                // element inert before the browser sees it. A template neither
                // creates a child realm nor fetches src/srcdoc resources.
                $result .= $this->neutralizedOpeningTag($html, $tagOffset, $tag);

                if ($tag['name'] === 'frame') {
                    $result .= '</template>';
                } elseif ($tag['name'] === 'iframe') {
                    $rawTextTag = $tag['name'];
                } else {
                    $neutralizedContainers[] = $tag['name'];
                }

                continue;
            }

            $result .= $tagText;

            if ($tag['name'] === 'plaintext') {
                return $result . substr($html, $offset);
            }

            // Enter foreign content on a non-self-closing <svg>/<math>. A self-closing
            // element (<svg/>) has no subtree, so it must not shift the depth.
            if (
                ($tag['name'] === 'svg' || $tag['name'] === 'math')
                && ($html[$tag['end'] - 1] ?? '') !== '/'
            ) {
                $foreignDepth++;
            }

            if (
                in_array($tag['name'], self::RAW_TEXT_TAGS, true)
                && !($foreignDepth > 0 && in_array($tag['name'], self::FOREIGN_PARSED_TEXT_TAGS, true))
            ) {
                $rawTextTag = $tag['name'];
            }
        }

        return $result;
    }

    /**
     * Interior bytes of a raw-text element that is being relocated. Only a
     * neutralized nested-browsing-context tag (iframe) has its opening tag
     * rewritten to a <template>, moving its former RAWTEXT interior into a parsed
     * context; there, '<' and '&' would become markup/character references, so a
     * literal </template> could close the inert wrapper and a following
     * <iframe srcdoc> would start a live fresh realm. Escaping both bytes keeps the
     * interior inspectable while making it inert. Non-nested raw-text tags (script,
     * style, textarea, ...) keep their bytes verbatim.
     */
    private function relocatedRawText(string $rawText, string $rawTextTag): string
    {
        if (!in_array($rawTextTag, self::NESTED_BROWSING_CONTEXT_TAGS, true)) {
            return $rawText;
        }

        // Escape '&' before '<' so the '&' introduced by '<' -> '&lt;' is not
        // re-escaped; str_replace applies the pairs left to right in one pass each.
        return str_replace(['&', '<'], ['&amp;', '&lt;'], $rawText);
    }

    /**
     * @return array{end: int, name: string, name_end: int, name_start: int, closing: bool}|null
     */
    private function tagAt(string $html, int $tagOffset): ?array
    {
        $length = strlen($html);
        $cursor = $tagOffset + 1;
        $closing = ($html[$cursor] ?? '') === '/';

        if ($closing) {
            ++$cursor;
        }

        $nameStart = $cursor;

        while ($cursor < $length) {
            $character = $html[$cursor];

            if ($this->isAsciiWhitespace($character) || $character === '/' || $character === '>') {
                break;
            }

            ++$cursor;
        }

        if ($cursor === $nameStart || preg_match('/^[A-Za-z]/', $html[$nameStart]) !== 1) {
            return null;
        }

        $end = $this->tagEnd($html, $tagOffset);

        if ($end === null) {
            return null;
        }

        return [
            'end' => $end,
            'name' => strtolower(substr($html, $nameStart, $cursor - $nameStart)),
            'name_end' => $cursor,
            'name_start' => $nameStart,
            'closing' => $closing,
        ];
    }

    private function tagEnd(string $html, int $tagOffset): ?int
    {
        $length = strlen($html);
        $quote = null;

        for ($cursor = $tagOffset + 1; $cursor < $length; ++$cursor) {
            $character = $html[$cursor];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }

            if ($character === '>') {
                return $cursor;
            }
        }

        return null;
    }

    private function findRawTextClosingTag(string $html, string $tagName, int $offset): ?int
    {
        $needle = '</' . $tagName;
        $candidate = stripos($html, $needle, $offset);

        while ($candidate !== false) {
            $boundary = $html[$candidate + strlen($needle)] ?? '';

            if ($boundary === '' || $boundary === '>' || $boundary === '/' || $this->isAsciiWhitespace($boundary)) {
                return $candidate;
            }

            $candidate = stripos($html, $needle, $candidate + 2);
        }

        return null;
    }

    /**
     * @param array{end: int, name: string, name_end: int, name_start: int, closing: bool} $tag
     */
    private function neutralizedOpeningTag(string $html, int $tagOffset, array $tag): string
    {
        $beforeName = substr($html, $tagOffset, $tag['name_start'] - $tagOffset);
        $afterName = substr($html, $tag['name_end'], $tag['end'] - $tag['name_end'] + 1);

        return $beforeName . 'template data-artifactflow-blocked-browsing-context' . $afterName;
    }

    private function isAsciiWhitespace(string $character): bool
    {
        return $character === "\t"
            || $character === "\n"
            || $character === "\f"
            || $character === "\r"
            || $character === ' ';
    }

    private function isRefreshMetaTag(string $tag): bool
    {
        foreach ($this->tagAttributes($tag) as $name => $value) {
            if ($name === 'http-equiv' && $this->normalizeAttributeValue($value) === 'refresh') {
                return true;
            }
        }

        return false;
    }

    private function isResourceHintLink(string $tag): bool
    {
        foreach ($this->tagAttributes($tag) as $name => $value) {
            if ($name !== 'rel') {
                continue;
            }

            $rels = preg_split('/\s+/', $this->normalizeAttributeValue($value), -1, PREG_SPLIT_NO_EMPTY);

            if (!is_array($rels)) {
                return false;
            }

            foreach ($rels as $rel) {
                if (in_array($rel, self::RESOURCE_HINT_RELS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function tagAttributes(string $tag): array
    {
        $matched = preg_match_all(
            '~([^\s"\'=<>`/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~',
            $tag,
            $attributes,
            PREG_SET_ORDER,
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        $parsed = [];

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute[1]);

            if (array_key_exists($name, $parsed)) {
                continue;
            }

            $parsed[$name] = $this->attributeValue($attribute);
        }

        return $parsed;
    }

    /**
     * @param array<int, string> $attribute
     */
    private function attributeValue(array $attribute): string
    {
        foreach ([2, 3, 4] as $index) {
            if (($attribute[$index] ?? '') !== '') {
                return $attribute[$index];
            }
        }

        return '';
    }

    private function normalizeAttributeValue(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $collapsed = preg_replace('/[\x00-\x20]+/', ' ', $decoded);

        return strtolower(trim(is_string($collapsed) ? $collapsed : $decoded));
    }

    private function injectAfterPattern(string $html, string $pattern, string $injectedHtml): ?string
    {
        $matched = preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE);

        if ($matched !== 1) {
            return null;
        }

        $match = $matches[0][0];
        $offset = $matches[0][1] + strlen($match);

        return substr($html, 0, $offset) . $injectedHtml . substr($html, $offset);
    }
}
