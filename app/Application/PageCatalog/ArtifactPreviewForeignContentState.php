<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

/**
 * Tracks only the foreign-content state that changes HTML tokenizer semantics.
 *
 * This is deliberately narrower than an HTML tree builder. It records foreign
 * elements, HTML/MathML integration points, and the foreign-content breakout
 * tokens that make the browser reprocess a token under HTML rules.
 */
final class ArtifactPreviewForeignContentState
{
    private const int MAX_OPEN_ELEMENTS = 16_384;

    /**
     * @var list<string>
     */
    private const array BREAKOUT_TAGS = [
        'b',
        'big',
        'blockquote',
        'body',
        'br',
        'center',
        'code',
        'dd',
        'div',
        'dl',
        'dt',
        'em',
        'embed',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'head',
        'hr',
        'i',
        'img',
        'li',
        'listing',
        'menu',
        'meta',
        'nobr',
        'ol',
        'p',
        'pre',
        'ruby',
        's',
        'small',
        'span',
        'strong',
        'strike',
        'sub',
        'sup',
        'table',
        'tt',
        'u',
        'ul',
        'var',
    ];

    /**
     * @var list<array{integration: string|null, name: string, namespace: string}>
     */
    private array $elements = [];

    /**
     * Open positions by element name make unmatched end tags O(1), while every
     * successful close pops each open element at most once.
     *
     * @var array<string, list<int>>
     */
    private array $positions = [];

    public function hasOpenForeignElement(): bool
    {
        return $this->elements !== [];
    }

    public function depth(): int
    {
        return count($this->elements);
    }

    public function hasOpenElementAtOrAfterDepth(string $name, int $depth): bool
    {
        $positions = $this->positions[$name] ?? [];

        return $positions !== [] && $positions[array_key_last($positions)] >= $depth;
    }

    public function restoreDepth(int $depth): void
    {
        while (count($this->elements) > $depth) {
            $this->pop();
        }
    }

    public function currentElementIsHtmlIntegrationPoint(): bool
    {
        if ($this->elements === []) {
            return false;
        }

        return $this->elements[array_key_last($this->elements)]['integration'] !== null;
    }

    public function currentElementIsMathTextIntegrationPoint(): bool
    {
        if ($this->elements === []) {
            return false;
        }

        return $this->elements[array_key_last($this->elements)]['integration'] === 'math_text';
    }

    public function startTagUsesHtmlTokenizer(string $name, string $tagText): bool
    {
        if ($this->elements === []) {
            return true;
        }

        $current = $this->elements[array_key_last($this->elements)];
        $integration = $current['integration'];

        if ($this->isMathAnnotationXmlSvgStart($current, $name)) {
            return true;
        }

        if ($integration === null) {
            return in_array($name, self::BREAKOUT_TAGS, true)
                || (
                    $name === 'font'
                    && (
                        $this->firstAttributeValue($tagText, 'color') !== null
                        || $this->firstAttributeValue($tagText, 'face') !== null
                        || $this->firstAttributeValue($tagText, 'size') !== null
                    )
                );
        }

        if ($integration === 'math_text' && in_array($name, ['malignmark', 'mglyph'], true)) {
            return false;
        }

        return true;
    }

    public function consumeStartTag(
        string $name,
        string $tagText,
        bool $selfClosing,
    ): bool {
        $current = $this->elements === []
            ? null
            : $this->elements[array_key_last($this->elements)];
        $annotationXmlSvgStart = is_array($current)
            && $this->isMathAnnotationXmlSvgStart($current, $name);
        $usesHtmlTokenizer = $this->startTagUsesHtmlTokenizer($name, $tagText);

        if (
            $this->elements !== []
            && $this->elements[array_key_last($this->elements)]['integration'] === null
            && $usesHtmlTokenizer
            && !$annotationXmlSvgStart
        ) {
            $this->popUntilIntegrationPoint();
        }

        if ($selfClosing) {
            return $usesHtmlTokenizer;
        }

        if ($usesHtmlTokenizer) {
            if ($name === 'svg' || $name === 'math') {
                $this->push($name, $name, null);
            }

            return true;
        }

        $namespace = $this->elements[count($this->elements) - 1]['namespace'];
        $this->push($name, $namespace, $this->integrationType($namespace, $name, $tagText));

        return false;
    }

    /**
     * HTML dispatches an `svg` start tag under the in-body rules whenever the
     * adjusted current node is MathML `annotation-xml`. Unlike an HTML
     * integration point, this transition does not depend on `encoding` and
     * does not pop the surrounding MathML elements.
     *
     * @param array{integration: string|null, name: string, namespace: string} $current
     */
    private function isMathAnnotationXmlSvgStart(array $current, string $name): bool
    {
        return $name === 'svg'
            && $current['namespace'] === 'math'
            && $current['name'] === 'annotation-xml';
    }

    public function consumeEndTag(string $name): void
    {
        if (
            $this->elements !== []
            && ($name === 'br' || $name === 'p')
        ) {
            // Foreign-content </br> and </p> tokens are reprocessed under
            // the active HTML insertion mode after the browser pops back to
            // an integration point or HTML element. Keeping deeper foreign
            // entries here would make later CDATA look inert when the browser
            // tokenizes it as a bogus comment followed by live markup.
            $this->popUntilIntegrationPoint();

            return;
        }

        $positions = $this->positions[$name] ?? [];

        if ($positions === []) {
            return;
        }

        $target = $positions[array_key_last($positions)];

        while (count($this->elements) > $target) {
            $this->pop();
        }
    }

    private function popUntilIntegrationPoint(): void
    {
        while ($this->elements !== []) {
            $current = $this->elements[array_key_last($this->elements)];

            if ($current['integration'] !== null) {
                return;
            }

            $this->pop();
        }
    }

    private function push(string $name, string $namespace, ?string $integration): void
    {
        $position = count($this->elements);

        if ($position >= self::MAX_OPEN_ELEMENTS) {
            throw new ArtifactPreviewComplexityExceeded(
                'Artifact preview foreign-content nesting exceeds the safe rendering limit.',
            );
        }

        $this->elements[] = [
            'integration' => $integration,
            'name' => $name,
            'namespace' => $namespace,
        ];
        $this->positions[$name] ??= [];
        $this->positions[$name][] = $position;
    }

    private function pop(): void
    {
        /** @var array{integration: string|null, name: string, namespace: string} $element */
        $element = array_pop($this->elements);

        array_pop($this->positions[$element['name']]);

        if ($this->positions[$element['name']] === []) {
            unset($this->positions[$element['name']]);
        }
    }

    private function integrationType(string $namespace, string $name, string $tagText): ?string
    {
        if (
            $namespace === 'svg'
            && in_array($name, ['desc', 'foreignobject', 'title'], true)
        ) {
            return 'html';
        }

        if (
            $namespace === 'math'
            && in_array($name, ['mi', 'mn', 'mo', 'ms', 'mtext'], true)
        ) {
            return 'math_text';
        }

        if (
            $namespace === 'math'
            && $name === 'annotation-xml'
            && in_array(
                strtolower($this->firstAttributeValue($tagText, 'encoding') ?? ''),
                ['application/xhtml+xml', 'text/html'],
                true,
            )
        ) {
            return 'math_annotation';
        }

        return null;
    }

    private function firstAttributeValue(string $tagText, string $wantedName): ?string
    {
        $length = strlen($tagText);
        $cursor = 1;

        while (
            $cursor < $length
            && !$this->isAsciiWhitespace($tagText[$cursor])
            && $tagText[$cursor] !== '/'
            && $tagText[$cursor] !== '>'
        ) {
            ++$cursor;
        }

        $state = 'before_attribute_name';
        $attributeName = '';
        $attributeValue = '';
        $wantedAttribute = false;

        while ($cursor < $length) {
            $character = $tagText[$cursor];

            switch ($state) {
                case 'before_attribute_name':
                    if ($this->isAsciiWhitespace($character)) {
                        ++$cursor;
                        break;
                    }

                    if ($character === '>') {
                        return null;
                    }

                    if ($character === '/') {
                        $state = 'self_closing_start_tag';
                        ++$cursor;
                        break;
                    }

                    $attributeName = '';
                    $attributeValue = '';
                    $state = 'attribute_name';
                    break;

                case 'attribute_name':
                    if (
                        $this->isAsciiWhitespace($character)
                        || $character === '/'
                        || $character === '='
                        || $character === '>'
                    ) {
                        $wantedAttribute = strtolower($attributeName) === $wantedName;

                        if ($this->isAsciiWhitespace($character)) {
                            $state = 'after_attribute_name';
                            ++$cursor;
                            break;
                        }

                        if ($character === '=') {
                            $state = 'before_attribute_value';
                            ++$cursor;
                            break;
                        }

                        if ($wantedAttribute) {
                            return '';
                        }

                        if ($character === '/') {
                            $state = 'self_closing_start_tag';
                            ++$cursor;
                            break;
                        }

                        return null;
                    }

                    $attributeName .= $character;
                    ++$cursor;
                    break;

                case 'after_attribute_name':
                    if ($this->isAsciiWhitespace($character)) {
                        ++$cursor;
                        break;
                    }

                    if ($character === '=') {
                        $state = 'before_attribute_value';
                        ++$cursor;
                        break;
                    }

                    if ($wantedAttribute) {
                        return '';
                    }

                    if ($character === '/') {
                        $state = 'self_closing_start_tag';
                        ++$cursor;
                        break;
                    }

                    if ($character === '>') {
                        return null;
                    }

                    $state = 'before_attribute_name';
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
                        return $wantedAttribute ? '' : null;
                    }

                    $state = 'attribute_value_unquoted';
                    break;

                case 'attribute_value_double_quoted':
                    if ($character === '"') {
                        if ($wantedAttribute) {
                            return $attributeValue;
                        }

                        $state = 'after_attribute_value_quoted';
                        ++$cursor;
                        break;
                    }

                    $attributeValue .= $character;
                    ++$cursor;
                    break;

                case 'attribute_value_single_quoted':
                    if ($character === "'") {
                        if ($wantedAttribute) {
                            return $attributeValue;
                        }

                        $state = 'after_attribute_value_quoted';
                        ++$cursor;
                        break;
                    }

                    $attributeValue .= $character;
                    ++$cursor;
                    break;

                case 'attribute_value_unquoted':
                    if ($this->isAsciiWhitespace($character) || $character === '>') {
                        if ($wantedAttribute) {
                            return $attributeValue;
                        }

                        if ($character === '>') {
                            return null;
                        }

                        $state = 'before_attribute_name';
                        ++$cursor;
                        break;
                    }

                    $attributeValue .= $character;
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
                        return null;
                    }

                    $state = 'before_attribute_name';
                    break;

                case 'self_closing_start_tag':
                    if ($character === '>') {
                        return null;
                    }

                    $state = 'before_attribute_name';
                    break;
            }
        }

        return $wantedAttribute ? $attributeValue : null;
    }

    private function isAsciiWhitespace(string $character): bool
    {
        return $character === ' '
            || $character === "\t"
            || $character === "\n"
            || $character === "\f"
            || $character === "\r";
    }
}
