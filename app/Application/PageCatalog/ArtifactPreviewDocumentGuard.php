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
        // Open SVG/MathML roots for the current foreign-content subtree. Tracking
        // their names prevents an unmatched foreign end tag from moving this
        // scanner back to the HTML raw-text rules before the browser does.
        /** @var list<string> $foreignRoots */
        $foreignRoots = [];

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
                $commentEnd = $this->htmlCommentEnd($html, $tagOffset);

                if ($commentEnd === null) {
                    return $result . substr($html, $tagOffset);
                }

                $commentLength = $commentEnd + 1 - $tagOffset;
                $result .= substr($html, $tagOffset, $commentLength);
                $offset = $commentEnd + 1;
                continue;
            }

            $nextCharacter = $html[$tagOffset + 1] ?? '';

            if ($nextCharacter === '!' || $nextCharacter === '?') {
                $declarationEnd = $this->declarationEnd($html, $tagOffset);

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
                if ($foreignRoots !== [] && ($tag['name'] === 'svg' || $tag['name'] === 'math')) {
                    for ($rootIndex = count($foreignRoots) - 1; $rootIndex >= 0; --$rootIndex) {
                        if ($foreignRoots[$rootIndex] !== $tag['name']) {
                            continue;
                        }

                        $foreignRoots = array_slice($foreignRoots, 0, $rootIndex);
                        break;
                    }
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
                $foreignRoots[] = $tag['name'];
            }

            if (
                in_array($tag['name'], self::RAW_TEXT_TAGS, true)
                && !($foreignRoots !== [] && in_array($tag['name'], self::FOREIGN_PARSED_TEXT_TAGS, true))
            ) {
                $rawTextTag = $tag['name'];
            }
        }

        return $result;
    }

    /**
     * Locate the closing `>` of an HTML comment using the browser tokenizer's
     * comment states. Searching only for `-->` creates a parser differential:
     * browsers also emit the comment at `--!>` and abruptly close an empty
     * comment at `<!-->` or `<!--->`, after which following markup is live.
     *
     * The comment bytes stay verbatim; this scanner only determines where the
     * browser returns to the data state so nested-context rewriting resumes at
     * the correct offset.
     */
    private function htmlCommentEnd(string $html, int $commentOffset): ?int
    {
        $length = strlen($html);
        $cursor = $commentOffset + 4;
        $state = 'start';

        while ($cursor < $length) {
            $character = $html[$cursor];

            switch ($state) {
                case 'start':
                    if ($character === '-') {
                        $state = 'start_dash';
                    } elseif ($character === '>') {
                        return $cursor;
                    } else {
                        $state = 'comment';
                        continue 2;
                    }
                    break;

                case 'start_dash':
                    if ($character === '-') {
                        $state = 'end';
                    } elseif ($character === '>') {
                        return $cursor;
                    } else {
                        $state = 'comment';
                        continue 2;
                    }
                    break;

                case 'comment':
                    if ($character === '<') {
                        $state = 'less_than';
                    } elseif ($character === '-') {
                        $state = 'end_dash';
                    }
                    break;

                case 'less_than':
                    if ($character === '!') {
                        $state = 'less_than_bang';
                    } elseif ($character !== '<') {
                        $state = 'comment';
                        continue 2;
                    }
                    break;

                case 'less_than_bang':
                    if ($character === '-') {
                        $state = 'less_than_bang_dash';
                    } else {
                        $state = 'comment';
                        continue 2;
                    }
                    break;

                case 'less_than_bang_dash':
                    if ($character === '-') {
                        $state = 'less_than_bang_dash_dash';
                    } else {
                        $state = 'end_dash';
                        continue 2;
                    }
                    break;

                case 'less_than_bang_dash_dash':
                    $state = 'end';
                    continue 2;

                case 'end_dash':
                    if ($character === '-') {
                        $state = 'end';
                    } else {
                        $state = 'comment';
                        continue 2;
                    }
                    break;

                case 'end':
                    if ($character === '>') {
                        return $cursor;
                    }

                    if ($character === '!') {
                        $state = 'end_bang';
                    } elseif ($character !== '-') {
                        $state = 'comment';
                        continue 2;
                    }
                    break;

                case 'end_bang':
                    if ($character === '-') {
                        $state = 'end_dash';
                    } elseif ($character === '>') {
                        return $cursor;
                    } else {
                        $state = 'comment';
                        continue 2;
                    }
                    break;
            }

            ++$cursor;
        }

        return null;
    }

    /**
     * Non-comment markup declarations, processing instructions, and malformed
     * DOCTYPE identifiers return the browser tokenizer to the data state at the
     * first `>`. Quotes do not protect that delimiter in those states. Reusing
     * the quote-aware start-tag scanner here would swallow following live markup
     * that the browser parses after an earlier `>`.
     *
     * In foreign-content CDATA this conservative boundary can resume scanning
     * before `]]>` and neutralize inert text, but it cannot miss a live nested
     * browsing context. Real start/end tags continue to use tagEnd().
     */
    private function declarationEnd(string $html, int $tagOffset): ?int
    {
        $end = strpos($html, '>', $tagOffset + 2);

        return $end === false ? null : $end;
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

        $end = $this->tagEnd($html, $cursor);

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

    /**
     * Locate the closing `>` using the browser's start-tag attribute states.
     * A quote starts a quoted attribute value only after `=`; quotes encountered
     * in malformed attribute names are ordinary name bytes and cannot protect a
     * later `>` from the browser tokenizer.
     */
    private function tagEnd(string $html, int $attributesOffset): ?int
    {
        $length = strlen($html);
        $cursor = $attributesOffset;
        $state = 'before_attribute_name';

        while ($cursor < $length) {
            $character = $html[$cursor];

            switch ($state) {
                case 'before_attribute_name':
                    if ($this->isAsciiWhitespace($character)) {
                        ++$cursor;
                        break;
                    }

                    if ($character === '>') {
                        return $cursor;
                    }

                    if ($character === '/') {
                        $state = 'self_closing_start_tag';
                        ++$cursor;
                        break;
                    }

                    if ($character === '=') {
                        // WHATWG's unexpected-equals-sign-before-attribute-name
                        // case starts a new attribute whose name already contains
                        // this byte. Reconsuming it would instead open an empty
                        // attribute value and let a following quote hide markup.
                        $state = 'attribute_name';
                        ++$cursor;
                        break;
                    }

                    $state = 'attribute_name';
                    break;

                case 'attribute_name':
                    if ($this->isAsciiWhitespace($character)) {
                        $state = 'after_attribute_name';
                        ++$cursor;
                        break;
                    }

                    if ($character === '/') {
                        $state = 'self_closing_start_tag';
                        ++$cursor;
                        break;
                    }

                    if ($character === '=') {
                        $state = 'before_attribute_value';
                        ++$cursor;
                        break;
                    }

                    if ($character === '>') {
                        return $cursor;
                    }

                    ++$cursor;
                    break;

                case 'after_attribute_name':
                    if ($this->isAsciiWhitespace($character)) {
                        ++$cursor;
                        break;
                    }

                    if ($character === '/') {
                        $state = 'self_closing_start_tag';
                        ++$cursor;
                        break;
                    }

                    if ($character === '=') {
                        $state = 'before_attribute_value';
                        ++$cursor;
                        break;
                    }

                    if ($character === '>') {
                        return $cursor;
                    }

                    $state = 'attribute_name';
                    break;

                case 'before_attribute_value':
                    if ($this->isAsciiWhitespace($character)) {
                        ++$cursor;
                        break;
                    }

                    if ($character === '"') {
                        $state = 'attribute_value_double_quoted';
                        ++$cursor;
                        break;
                    }

                    if ($character === "'") {
                        $state = 'attribute_value_single_quoted';
                        ++$cursor;
                        break;
                    }

                    if ($character === '>') {
                        return $cursor;
                    }

                    $state = 'attribute_value_unquoted';
                    break;

                case 'attribute_value_double_quoted':
                    if ($character === '"') {
                        $state = 'after_attribute_value_quoted';
                    }

                    ++$cursor;
                    break;

                case 'attribute_value_single_quoted':
                    if ($character === "'") {
                        $state = 'after_attribute_value_quoted';
                    }

                    ++$cursor;
                    break;

                case 'attribute_value_unquoted':
                    if ($this->isAsciiWhitespace($character)) {
                        $state = 'before_attribute_name';
                        ++$cursor;
                        break;
                    }

                    if ($character === '>') {
                        return $cursor;
                    }

                    ++$cursor;
                    break;

                case 'after_attribute_value_quoted':
                    if ($this->isAsciiWhitespace($character)) {
                        $state = 'before_attribute_name';
                        ++$cursor;
                        break;
                    }

                    if ($character === '/') {
                        $state = 'self_closing_start_tag';
                        ++$cursor;
                        break;
                    }

                    if ($character === '>') {
                        return $cursor;
                    }

                    $state = 'before_attribute_name';
                    break;

                case 'self_closing_start_tag':
                    if ($character === '>') {
                        return $cursor;
                    }

                    $state = 'before_attribute_name';
                    break;
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
