<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use RuntimeException;

final class ArtifactPreviewDocumentGuard
{
    private const int MAX_REWRITE_DEPTH = 64;

    private const int MAX_SHADOW_ROOT_ATTRIBUTES_PER_TAG = 4_096;

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
        $html = $this->rewriteDangerousMarkup(
            $html,
            rewriteBudget: new ArtifactPreviewRewriteBudget(strlen($html)),
        );
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

    private function rewriteDangerousMarkup(
        string $html,
        bool $scriptingEnabled = true,
        int $rewriteDepth = 0,
        ?ArtifactPreviewRewriteBudget $rewriteBudget = null,
    ): string {
        if ($rewriteDepth > self::MAX_REWRITE_DEPTH) {
            throw new ArtifactPreviewComplexityExceeded(
                'Artifact preview alternate parser nesting exceeds the safe rendering limit.',
            );
        }

        $rewriteBudget ??= new ArtifactPreviewRewriteBudget(strlen($html));
        $rewriteBudget->consume(strlen($html));

        $length = strlen($html);
        $offset = 0;
        $result = '';
        $rawTextTag = null;
        $neutralizedContainers = [];
        $foreignContent = new ArtifactPreviewForeignContentState();
        $openFramesets = 0;
        $openSelects = 0;
        $selectNoframesRawTextAlternate = false;

        while ($offset < $length) {
            if ($rawTextTag !== null) {
                $closingOffset = $this->findRawTextClosingTag($html, $rawTextTag, $offset);

                if ($closingOffset === null) {
                    // Unterminated raw-text element: everything to EOF is its
                    // interior. A neutralized iframe's interior was relocated into a
                    // parsed template, so it must be escaped here too -- otherwise an
                    // embedded </template> closes the wrapper and following bytes
                    // (e.g. <iframe srcdoc>) parse as a live nested browsing context.
                    $interior = substr($html, $offset);

                    if ($rawTextTag === 'noscript') {
                        $interior = $this->rewriteDangerousMarkup(
                            $interior,
                            scriptingEnabled: false,
                            rewriteDepth: $rewriteDepth + 1,
                            rewriteBudget: $rewriteBudget,
                        );
                    }

                    return $result . $this->relocatedRawText($interior, $rawTextTag);
                }

                $interior = substr($html, $offset, $closingOffset - $offset);

                if ($rawTextTag === 'noscript') {
                    // The outer scan follows the scripting-enabled parse, where
                    // noscript is raw text. A second, scripting-disabled pass over
                    // only its interior covers the browser mode where those same
                    // bytes are live markup.
                    $interior = $this->rewriteDangerousMarkup(
                        $interior,
                        scriptingEnabled: false,
                        rewriteDepth: $rewriteDepth + 1,
                        rewriteBudget: $rewriteBudget,
                    );
                }

                $result .= $this->relocatedRawText($interior, $rawTextTag);
                /**
                 * findRawTextClosingTag only returns offsets whose complete
                 * closing tag boundary has already been validated.
                 *
                 * @var array{end: int, name: string, name_end: int, name_start: int, closing: bool, self_closing: bool} $closingTag
                 */
                $closingTag = $this->tagAt($html, $closingOffset);

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
                if (
                    $nextCharacter === '!'
                    && $foreignContent->hasOpenForeignElement()
                    && substr_compare($html, '<![CDATA[', $tagOffset, 9) === 0
                ) {
                    $cdata = $this->rewriteForeignCdataSection(
                        $html,
                        $tagOffset,
                        $scriptingEnabled,
                        $foreignContent->currentElementIsHtmlIntegrationPoint(),
                        $openFramesets > 0,
                        $rewriteDepth,
                        $rewriteBudget,
                    );
                    $result .= $cdata['rewritten'];
                    $offset = $cdata['end'] + 1;
                    continue;
                }

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
                if ($openSelects === 0) {
                    $foreignContent->consumeEndTag($tag['name']);
                }

                if ($tag['name'] === 'select' && $openSelects > 0) {
                    --$openSelects;
                }

                if ($tag['name'] === 'frameset' && $openFramesets > 0) {
                    --$openFramesets;
                }

                if ($tag['name'] === 'noframes') {
                    $selectNoframesRawTextAlternate = false;
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

            if ($tag['name'] === 'template') {
                $tagText = $this->neutralizeDeclarativeShadowRoot($tagText);
            }

            if (in_array($tag['name'], self::NESTED_BROWSING_CONTEXT_TAGS, true)) {
                $usesHtmlTokenizer = $foreignContent->startTagUsesHtmlTokenizer($tag['name'], $tagText);

                // Keep attributes and fallback bytes inspectable, but make the
                // element inert before the browser sees it. A template neither
                // creates a child realm nor fetches src/srcdoc resources.
                $result .= $this->neutralizedOpeningTag($html, $tagOffset, $tag);

                if ($tag['name'] === 'frame') {
                    $result .= '</template>';
                } elseif ($tag['name'] === 'iframe' && $usesHtmlTokenizer) {
                    $rawTextTag = $tag['name'];
                } else {
                    $neutralizedContainers[] = $tag['name'];
                }

                continue;
            }

            // The in-select tree-builder mode ignores foreign start tags. Do
            // not let an element the browser never created enter the foreign
            // stack and change later CDATA tokenization. Frameset parsing is
            // maintained as a separate alternate: the ordinary foreign path
            // preserves inert CDATA, while the frameset path independently
            // neutralizes live <frame> tokens inside the same bytes.
            $selectStartsAtMathTextIntegrationPoint = $tag['name'] === 'select'
                && $openSelects === 0
                && $foreignContent->currentElementIsMathTextIntegrationPoint();
            $usesHtmlTokenizer = $openSelects > 0
                ? true
                : $foreignContent->consumeStartTag(
                    $tag['name'],
                    $tagText,
                    $tag['self_closing'],
                );
            $result .= $tagText;

            if ($tag['name'] === 'noframes' && $openSelects > 0) {
                // Firefox ignores noframes in select, while maintained
                // Chromium/WebKit tokenize it as raw text. Scan its interior
                // as live markup until the first matching close: bytes rewritten
                // for Firefox remain text in the raw-text interpretation, and
                // markup after the close cannot hide behind a false script state.
                $selectNoframesRawTextAlternate = true;
            }

            $startsRawText = !$selectNoframesRawTextAlternate
                && $usesHtmlTokenizer
                && $this->startTagUsesRawTextTokenizer(
                    $tag['name'],
                    $scriptingEnabled,
                    $openFramesets,
                    $openSelects,
                );

            if (
                $tag['name'] === 'plaintext'
                && $usesHtmlTokenizer
                && $openFramesets === 0
                && $openSelects === 0
            ) {
                return $result . substr($html, $offset);
            }

            if ($usesHtmlTokenizer) {
                if ($tag['name'] === 'select') {
                    if ($openFramesets === 0 && !$selectStartsAtMathTextIntegrationPoint) {
                        // In "in select", another select start tag closes the
                        // active select instead of opening a nested one. In
                        // "in frameset" it is ignored altogether. A select
                        // token at a MathML text integration point has
                        // divergent maintained-engine behavior, so keep
                        // scanning the foreign-content interpretation instead
                        // of allowing a false script RAWTEXT state to hide
                        // later markup.
                        $openSelects += $openSelects > 0 ? -1 : 1;
                    }
                }

                if ($tag['name'] === 'frameset' && $openSelects === 0) {
                    // The "in select" tree-builder mode ignores frameset.
                    // Counting that nonexistent element would make a later
                    // noframes token look like RAWTEXT and hide live markup.
                    ++$openFramesets;
                }
            }

            if ($startsRawText) {
                $rawTextTag = $tag['name'];
            }
        }

        return $result;
    }

    private function startTagUsesRawTextTokenizer(
        string $tagName,
        bool $scriptingEnabled,
        int $openFramesets,
        int $openSelects,
    ): bool {
        if ($openFramesets > 0) {
            // "in frameset" delegates only noframes to the "in head"
            // rules, where its contents use RAWTEXT. Tracking a fake
            // </frameset> inside those bytes would make a later plaintext
            // token look effective and leave following frame markup live.
            return $tagName === 'noframes';
        }

        if ($openSelects > 0) {
            // "in select" delegates script to the "in head" rules. Other
            // raw-text-looking start tags are ignored by the tree builder.
            return $tagName === 'script';
        }

        return in_array($tagName, self::RAW_TEXT_TAGS, true)
            || ($scriptingEnabled && $tagName === 'noscript');
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
     * Foreign-content CDATA is handled separately because Firefox follows the
     * CDATA state through `]]>`, while maintained Chromium/WebKit releases can
     * treat the same integration-point bytes as a bogus comment. Real start/end
     * tags continue to use tagBoundary().
     */
    private function declarationEnd(string $html, int $tagOffset): ?int
    {
        $end = strpos($html, '>', $tagOffset + 2);

        return $end === false ? null : $end;
    }

    /**
     * Preserve ordinary foreign CDATA verbatim. At an HTML integration point,
     * also harden bytes that Chromium/WebKit can parse as live markup after the
     * bogus comment's first `>`. The outer scan resumes after `]]>` so
     * Firefox-live suffix markup cannot be swallowed by a raw-text carrier found
     * only in the alternate parse. When a frameset interpretation is active,
     * the same bytes are scanned separately for live frame tokens without
     * rewriting nested-context names that the frameset mode ignores.
     *
     * @return array{end: int, rewritten: string}
     */
    private function rewriteForeignCdataSection(
        string $html,
        int $tagOffset,
        bool $scriptingEnabled,
        bool $maintainBogusCommentInterpretation,
        bool $maintainFramesetInterpretation,
        int $rewriteDepth,
        ArtifactPreviewRewriteBudget $rewriteBudget,
    ): array {
        $terminator = strpos($html, ']]>', $tagOffset + 9);
        $sectionEnd = $terminator === false ? strlen($html) - 1 : $terminator + 2;

        if (!$maintainBogusCommentInterpretation && !$maintainFramesetInterpretation) {
            return [
                'end' => $sectionEnd,
                'rewritten' => substr($html, $tagOffset, $sectionEnd - $tagOffset + 1),
            ];
        }

        $bogusCommentEnd = strpos($html, '>', $tagOffset + 2);

        if ($bogusCommentEnd === false || $bogusCommentEnd >= $sectionEnd) {
            return [
                'end' => $sectionEnd,
                'rewritten' => substr($html, $tagOffset, $sectionEnd - $tagOffset + 1),
            ];
        }

        $prefixLength = $bogusCommentEnd - $tagOffset + 1;
        $alternateLength = $sectionEnd - $bogusCommentEnd;
        $alternateMarkup = substr($html, $bogusCommentEnd + 1, $alternateLength);
        $rewrittenAlternate = $maintainBogusCommentInterpretation
            ? $this->rewriteDangerousMarkup(
                $alternateMarkup,
                $scriptingEnabled,
                $rewriteDepth + 1,
                $rewriteBudget,
            )
            : $this->rewriteFramesetAlternateMarkup($alternateMarkup);

        return [
            'end' => $sectionEnd,
            'rewritten' => substr($html, $tagOffset, $prefixLength)
                . $rewrittenAlternate,
        ];
    }

    /**
     * Scan the bytes that a foreign-content branch keeps as CDATA but the
     * frameset insertion mode sees after a bogus-comment close. In frameset
     * mode only frame creates a nested browsing context; iframe, portal, and
     * fencedframe tokens are ignored and must remain byte-exact in the foreign
     * branch. Noframes still uses RAWTEXT and hides any frame-looking bytes in
     * its interior.
     */
    private function rewriteFramesetAlternateMarkup(string $html): string
    {
        $length = strlen($html);
        $offset = 0;
        $result = '';

        while ($offset < $length) {
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

                $result .= substr($html, $tagOffset, $commentEnd + 1 - $tagOffset);
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
                $result .= $tagText;
                continue;
            }

            if ($tag['name'] === 'noframes') {
                $closingOffset = $this->findRawTextClosingTag($html, 'noframes', $offset);

                if ($closingOffset === null) {
                    return $result . $tagText . substr($html, $offset);
                }

                $closingTag = $this->tagAt($html, $closingOffset);
                /** @var array{end: int, name: string, name_end: int, name_start: int, closing: bool, self_closing: bool} $closingTag */

                $result .= $tagText
                    . substr($html, $offset, $closingTag['end'] + 1 - $offset);
                $offset = $closingTag['end'] + 1;
                continue;
            }

            if ($tag['name'] === 'frame') {
                $result .= $this->neutralizedOpeningTag($html, $tagOffset, $tag) . '</template>';
                continue;
            }

            $result .= $tagText;
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
     * @return array{
     *     end: int,
     *     name: string,
     *     name_end: int,
     *     name_start: int,
     *     closing: bool,
     *     self_closing: bool
     * }|null
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

        $boundary = $this->tagBoundary($html, $cursor);

        if ($boundary === null) {
            return null;
        }

        return [
            'end' => $boundary['end'],
            'name' => strtolower(substr($html, $nameStart, $cursor - $nameStart)),
            'name_end' => $cursor,
            'name_start' => $nameStart,
            'closing' => $closing,
            'self_closing' => $boundary['self_closing'],
        ];
    }

    /**
     * Locate the closing `>` and self-closing flag using the browser's start-tag
     * attribute states.
     *
     * A quote starts a quoted attribute value only after `=`; quotes encountered
     * in malformed attribute names are ordinary name bytes and cannot protect a
     * later `>` from the browser tokenizer.
     *
     * @return array{end: int, self_closing: bool}|null
     */
    private function tagBoundary(string $html, int $attributesOffset): ?array
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
                        return ['end' => $cursor, 'self_closing' => false];
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
                        return ['end' => $cursor, 'self_closing' => false];
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
                        return ['end' => $cursor, 'self_closing' => false];
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
                        return ['end' => $cursor, 'self_closing' => false];
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
                        return ['end' => $cursor, 'self_closing' => false];
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
                        return ['end' => $cursor, 'self_closing' => false];
                    }

                    $state = 'before_attribute_name';
                    break;

                case 'self_closing_start_tag':
                    if ($character === '>') {
                        return ['end' => $cursor, 'self_closing' => true];
                    }

                    $state = 'before_attribute_name';
                    break;
            }
        }

        return null;
    }

    private function findRawTextClosingTag(string $html, string $tagName, int $offset): ?int
    {
        if ($tagName === 'script') {
            $candidate = $this->findScriptClosingTag($html, $offset);

            return $candidate !== null && $this->tagAt($html, $candidate) !== null
                ? $candidate
                : null;
        }

        $needle = '</' . $tagName;
        $candidate = stripos($html, $needle, $offset);

        while ($candidate !== false) {
            if (
                $this->hasRawTextTagBoundary($html, $candidate + strlen($needle))
                && $this->tagAt($html, $candidate) !== null
            ) {
                return $candidate;
            }

            $candidate = stripos($html, $needle, $candidate + 2);
        }

        return null;
    }

    /**
     * Script data has an extra escaped/double-escaped tokenizer state machine.
     * In the double-escaped state, `</script>` returns the browser to the escaped
     * state instead of closing the element. Treating that first sequence as the
     * close desynchronizes the rewriter and can leave later live markup unscanned.
     */
    private function findScriptClosingTag(string $html, int $offset): ?int
    {
        $length = strlen($html);
        $cursor = $offset;
        $state = 'data';

        while ($cursor < $length) {
            $character = $html[$cursor];

            if ($state === 'data') {
                if ($character !== '<') {
                    ++$cursor;
                    continue;
                }

                if ($this->hasScriptTagSequence($html, $cursor, '</script')) {
                    return $cursor;
                }

                if (substr_compare($html, '<!--', $cursor, 4) === 0) {
                    // Consuming <!-- leaves the tokenizer in script-data
                    // escaped-dash-dash, not a generic escaped state. The
                    // immediately overlapping > in <!--> therefore returns
                    // to script data without requiring a later literal -->.
                    $state = 'escaped_dash_dash';
                    $cursor += 4;
                    continue;
                }

                ++$cursor;
                continue;
            }

            $doubleEscaped = str_starts_with($state, 'double_escaped');

            if ($character === '<') {
                if ($doubleEscaped) {
                    if ($this->hasScriptTagSequence($html, $cursor, '</script')) {
                        $state = 'escaped';
                        $cursor += 8;
                        continue;
                    }

                    $state = 'double_escaped';
                    ++$cursor;
                    continue;
                }

                if ($this->hasScriptTagSequence($html, $cursor, '</script')) {
                    return $cursor;
                }

                if ($this->hasScriptTagSequence($html, $cursor, '<script')) {
                    $state = 'double_escaped';
                    $cursor += 7;
                    continue;
                }

                $state = 'escaped';
                ++$cursor;
                continue;
            }

            if ($character === '-') {
                $state = match (true) {
                    $state === 'escaped' => 'escaped_dash',
                    $state === 'double_escaped' => 'double_escaped_dash',
                    $doubleEscaped => 'double_escaped_dash_dash',
                    default => 'escaped_dash_dash',
                };

                ++$cursor;
                continue;
            }

            if ($character === '>' && str_ends_with($state, '_dash_dash')) {
                // Returning to data from double-escaped dash-dash is a
                // deliberate conservative over-approximation: it scans more
                // bytes as markup than the browser, never fewer, and preserves
                // the existing hardening of ambiguous comment-like scripts.
                $state = 'data';
                ++$cursor;
                continue;
            }

            $state = $doubleEscaped ? 'double_escaped' : 'escaped';
            ++$cursor;
        }

        return null;
    }

    private function hasScriptTagSequence(string $html, int $offset, string $sequence): bool
    {
        $sequenceLength = strlen($sequence);

        return substr_compare($html, $sequence, $offset, $sequenceLength, true) === 0
            && $this->hasRawTextTagBoundary($html, $offset + $sequenceLength);
    }

    private function hasRawTextTagBoundary(string $html, int $offset): bool
    {
        $boundary = $html[$offset] ?? '';

        return $boundary === ''
            || $boundary === '>'
            || $boundary === '/'
            || $this->isAsciiWhitespace($boundary);
    }

    /**
     * @param array{
     *     end: int,
     *     name: string,
     *     name_end: int,
     *     name_start: int,
     *     closing: bool,
     *     self_closing: bool
     * } $tag
     */
    private function neutralizedOpeningTag(string $html, int $tagOffset, array $tag): string
    {
        $beforeName = substr($html, $tagOffset, $tag['name_start'] - $tagOffset);
        $afterName = substr($html, $tag['name_end'], $tag['end'] - $tag['name_end'] + 1);
        $neutralizedTag = $beforeName
            . 'template data-artifactflow-blocked-browsing-context'
            . $afterName;

        return $this->neutralizeDeclarativeShadowRoot($neutralizedTag);
    }

    /**
     * Declarative Shadow DOM materializes template contents during parsing and
     * hides closed roots from the residual DOM sweep. Rename every actual
     * shadowrootmode attribute before the browser parses the document, while
     * preserving identical text inside quoted values or malformed attribute
     * names.
     */
    private function neutralizeDeclarativeShadowRoot(string $tag): string
    {
        $length = strlen($tag);
        $cursor = 1;

        while (
            $cursor < $length
            && !$this->isAsciiWhitespace($tag[$cursor])
            && $tag[$cursor] !== '/'
            && $tag[$cursor] !== '>'
        ) {
            ++$cursor;
        }

        $state = 'before_attribute_name';
        $attributeNameStart = null;
        /** @var list<array{length: int, start: int}> $ranges */
        $ranges = [];

        while ($cursor < $length) {
            $character = $tag[$cursor];

            switch ($state) {
                case 'before_attribute_name':
                    if ($this->isAsciiWhitespace($character)) {
                        ++$cursor;
                        break;
                    }

                    if ($character === '>') {
                        break 2;
                    }

                    if ($character === '/') {
                        $state = 'self_closing_start_tag';
                        ++$cursor;
                        break;
                    }

                    $attributeNameStart = $cursor;
                    $state = 'attribute_name';
                    ++$cursor;
                    break;

                case 'attribute_name':
                    if (
                        $this->isAsciiWhitespace($character)
                        || $character === '/'
                        || $character === '='
                        || $character === '>'
                    ) {
                        if (
                            is_int($attributeNameStart)
                            && strtolower(substr($tag, $attributeNameStart, $cursor - $attributeNameStart))
                                === 'shadowrootmode'
                        ) {
                            if (count($ranges) >= self::MAX_SHADOW_ROOT_ATTRIBUTES_PER_TAG) {
                                throw new ArtifactPreviewComplexityExceeded(
                                    'Artifact preview attribute volume exceeds the safe rendering limit.',
                                );
                            }

                            $ranges[] = [
                                'length' => $cursor - $attributeNameStart,
                                'start' => $attributeNameStart,
                            ];
                        }

                        $attributeNameStart = null;

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

                        break 2;
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
                        break 2;
                    }

                    $attributeNameStart = $cursor;
                    $state = 'attribute_name';
                    ++$cursor;
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
                        break 2;
                    }

                    $state = 'attribute_value_unquoted';
                    ++$cursor;
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
                        break 2;
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
                        break 2;
                    }

                    $state = 'before_attribute_name';
                    break;

                case 'self_closing_start_tag':
                    if ($character === '>') {
                        break 2;
                    }

                    $state = 'before_attribute_name';
                    break;
            }
        }

        if ($ranges === []) {
            return $tag;
        }

        // Rebuild once from ordered ranges. Repeated substr_replace calls copy
        // the growing tag on every duplicate attribute and turn a bounded HTML
        // upload into quadratic CPU and allocation work.
        $rewritten = '';
        $copyOffset = 0;

        foreach ($ranges as $range) {
            $rewritten .= substr($tag, $copyOffset, $range['start'] - $copyOffset);
            $rewritten .= 'data-artifactflow-blocked-shadow-root';
            $copyOffset = $range['start'] + $range['length'];
        }

        return $rewritten . substr($tag, $copyOffset);
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

            /** @var list<string> $rels */
            $rels = preg_split('/\s+/', $this->normalizeAttributeValue($value), -1, PREG_SPLIT_NO_EMPTY);

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
        preg_match_all(
            '~([^\s"\'=<>`/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~',
            $tag,
            $attributes,
            PREG_SET_ORDER,
        );

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
        $decodedNumeric = preg_replace_callback(
            '/&#(?:[xX]([0-9A-Fa-f]+)|([0-9]+));?/',
            function (array $matches): string {
                $hexDigits = $matches[1];
                $digits = $hexDigits !== '' ? $hexDigits : ($matches[2] ?? '');
                $codePoint = $this->numericCharacterReferenceCodePoint(
                    $digits,
                    $hexDigits !== '' ? 16 : 10,
                );
                return mb_chr($codePoint, 'UTF-8');
            },
            $value,
        );
        $decoded = html_entity_decode(
            is_string($decodedNumeric) ? $decodedNumeric : $value,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $collapsed = preg_replace('/[\x00-\x20]+/', ' ', $decoded);

        return strtolower(trim(is_string($collapsed) ? $collapsed : $decoded));
    }

    private function numericCharacterReferenceCodePoint(string $digits, int $base): int
    {
        $codePoint = 0;

        for ($index = 0, $length = strlen($digits); $index < $length; ++$index) {
            $ordinal = ord($digits[$index]);
            $digit = $ordinal >= 48 && $ordinal <= 57
                ? $ordinal - 48
                : (strtolower($digits[$index]) === $digits[$index] ? $ordinal - 87 : $ordinal - 55);

            if ($codePoint > intdiv(0x10FFFF - $digit, $base)) {
                return 0xFFFD;
            }

            $codePoint = ($codePoint * $base) + $digit;
        }

        if (
            $codePoint === 0
            || $codePoint > 0x10FFFF
            || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)
        ) {
            return 0xFFFD;
        }

        return match ($codePoint) {
            0x80 => 0x20AC,
            0x82 => 0x201A,
            0x83 => 0x0192,
            0x84 => 0x201E,
            0x85 => 0x2026,
            0x86 => 0x2020,
            0x87 => 0x2021,
            0x88 => 0x02C6,
            0x89 => 0x2030,
            0x8A => 0x0160,
            0x8B => 0x2039,
            0x8C => 0x0152,
            0x8E => 0x017D,
            0x91 => 0x2018,
            0x92 => 0x2019,
            0x93 => 0x201C,
            0x94 => 0x201D,
            0x95 => 0x2022,
            0x96 => 0x2013,
            0x97 => 0x2014,
            0x98 => 0x02DC,
            0x99 => 0x2122,
            0x9A => 0x0161,
            0x9B => 0x203A,
            0x9C => 0x0153,
            0x9E => 0x017E,
            0x9F => 0x0178,
            default => $codePoint,
        };
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
