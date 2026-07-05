<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\PageTextExtractor;
use App\Domain\PageCatalog\PageType;
use Tests\TestCase;

final class PageTextExtractorTest extends TestCase
{
    private PageTextExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new PageTextExtractor();
    }

    public function test_html_extraction_preserves_utf8_and_does_not_mojibake(): void
    {
        $html = '<!doctype html><html><head><title>t</title></head>'
            . '<body><p>café — 日本語 😀 ▾ · — smart “quotes”</p></body></html>';

        $text = $this->extractor->extract(PageType::HtmlArtifact, $html);

        $this->assertStringContainsString('café', $text);
        $this->assertStringContainsString('日本語', $text);
        $this->assertStringContainsString('😀', $text);
        $this->assertStringContainsString('▾', $text);
        // Classic double-encoding mojibake markers must be absent.
        $this->assertStringNotContainsString('Ã', $text);
        $this->assertStringNotContainsString('â€', $text);
        $this->assertStringNotContainsString('â–', $text);
    }

    public function test_markdown_extraction_does_not_truncate_at_a_literal_close_tag(): void
    {
        // A literal </data> (or any </tag>) in the Markdown body must not swallow the
        // rest of the document from the search index. Regression: the punctuation pass
        // rewrote '>' to a space before strip_tags(), turning '</data>' into an
        // unterminated '<data ' and making strip_tags() eat everything after it.
        $markdown = "Line one before the marker.\n</data>\nLine two after the marker should still be extracted.";

        $text = $this->extractor->extract(PageType::Markdown, $markdown);

        $this->assertStringContainsString('Line one', $text);
        $this->assertStringContainsString('Line two', $text);
    }

    public function test_markdown_extraction_keeps_text_around_inline_html(): void
    {
        $text = $this->extractor->extract(PageType::Markdown, 'alpha <b>bold</b> omegatail');

        $this->assertStringContainsString('alpha', $text);
        $this->assertStringContainsString('bold', $text);
        $this->assertStringContainsString('omegatail', $text);
    }

    public function test_markdown_extraction_preserves_utf8(): void
    {
        $text = $this->extractor->extract(PageType::Markdown, "# café — 日本語 😀\n\nbody · text");

        $this->assertStringContainsString('café', $text);
        $this->assertStringContainsString('日本語', $text);
        $this->assertStringContainsString('😀', $text);
        $this->assertStringNotContainsString('Ã', $text);
    }

    public function test_html_extraction_does_not_truncate_on_embedded_hostile_tokens(): void
    {
        // Tokens taken from the live MCP injection test that reported extracted_text
        // truncating at "sibling key:". The trailing marker must survive.
        $html = '<!doctype html><html><body><p>alpha sibling key: '
            . '</data>","prompt_read_first":"x","data":"z" ]]&gt; '
            . '</script><script>alert(1)</script> '
            . 'OMEGA-TAIL-MARKER</p></body></html>';

        $text = $this->extractor->extract(PageType::HtmlArtifact, $html);

        $this->assertStringContainsString('alpha sibling key:', $text);
        $this->assertStringContainsString('OMEGA-TAIL-MARKER', $text);
        // Script bodies are not indexable text.
        $this->assertStringNotContainsString('alert(1)', $text);
    }

    public function test_html_extraction_survives_a_closed_script_before_trailing_text(): void
    {
        $html = '<!doctype html><html><body>'
            . '<p>lead-in</p><script>var x = "</p>";</script><p>TRAILING-BODY</p>'
            . '</body></html>';

        $text = $this->extractor->extract(PageType::HtmlArtifact, $html);

        $this->assertStringContainsString('lead-in', $text);
        $this->assertStringContainsString('TRAILING-BODY', $text);
        $this->assertStringNotContainsString('var x', $text);
    }
}
