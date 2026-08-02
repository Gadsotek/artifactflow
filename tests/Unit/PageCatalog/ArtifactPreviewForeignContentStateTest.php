<?php

declare(strict_types=1);

namespace Tests\Unit\PageCatalog;

use App\Application\PageCatalog\ArtifactPreviewForeignContentState;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ArtifactPreviewForeignContentStateTest extends TestCase
{
    public function test_foreign_stack_tracks_nested_elements_self_closing_tags_and_breakouts(): void
    {
        $state = new ArtifactPreviewForeignContentState();

        $this->assertFalse($state->hasOpenForeignElement());
        $this->assertFalse($state->currentElementIsHtmlIntegrationPoint());
        $this->assertTrue($state->startTagUsesHtmlTokenizer('div', '<div>'));

        $this->assertTrue($state->consumeStartTag('svg', '<svg>', false));
        $this->assertTrue($state->hasOpenForeignElement());
        $this->assertFalse($state->currentElementIsHtmlIntegrationPoint());
        $this->assertFalse($state->consumeStartTag('g', '<g>', false));
        $this->assertFalse($state->consumeStartTag('circle', '<circle/>', true));

        $state->consumeEndTag('missing');
        $this->assertTrue($state->hasOpenForeignElement());
        $state->consumeEndTag('g');
        $this->assertTrue($state->hasOpenForeignElement());

        $this->assertTrue($state->consumeStartTag('table', '<table>', false));
        $this->assertFalse($state->hasOpenForeignElement());
    }

    public function test_foreign_end_p_and_br_pop_only_as_far_as_an_integration_point(): void
    {
        $state = new ArtifactPreviewForeignContentState();
        $state->consumeStartTag('svg', '<svg>', false);
        $state->consumeStartTag('foreignobject', '<foreignObject>', false);
        $state->consumeStartTag('svg', '<svg>', false);
        $state->consumeStartTag('g', '<g>', false);

        $state->consumeEndTag('p');
        $this->assertTrue($state->currentElementIsHtmlIntegrationPoint());

        $state->consumeEndTag('br');
        $this->assertTrue($state->currentElementIsHtmlIntegrationPoint());
        $state->consumeEndTag('foreignobject');
        $this->assertFalse($state->currentElementIsHtmlIntegrationPoint());
        $state->consumeEndTag('svg');
        $this->assertFalse($state->hasOpenForeignElement());
    }

    public function test_math_text_and_annotation_integration_points_follow_their_tokenizer_exceptions(): void
    {
        $mathText = new ArtifactPreviewForeignContentState();
        $mathText->consumeStartTag('math', '<math>', false);
        $this->assertFalse($mathText->consumeStartTag('mi', '<mi>', false));
        $this->assertTrue($mathText->currentElementIsHtmlIntegrationPoint());
        $this->assertFalse($mathText->startTagUsesHtmlTokenizer('malignmark', '<malignmark>'));
        $this->assertFalse($mathText->startTagUsesHtmlTokenizer('mglyph', '<mglyph>'));
        $this->assertTrue($mathText->startTagUsesHtmlTokenizer('span', '<span>'));

        $annotation = new ArtifactPreviewForeignContentState();
        $annotation->consumeStartTag('math', '<math>', false);
        $this->assertFalse($annotation->consumeStartTag(
            'annotation-xml',
            '<annotation-xml encoding="application/xhtml+xml">',
            false,
        ));
        $this->assertTrue($annotation->currentElementIsHtmlIntegrationPoint());
        $this->assertTrue($annotation->consumeStartTag('svg', '<svg>', false));
        $this->assertFalse($annotation->currentElementIsHtmlIntegrationPoint());
        $annotation->consumeEndTag('svg');
        $this->assertTrue($annotation->currentElementIsHtmlIntegrationPoint());
    }

    public function test_svg_start_under_unencoded_annotation_xml_uses_the_html_dispatch_exception(): void
    {
        $state = new ArtifactPreviewForeignContentState();
        $state->consumeStartTag('math', '<math>', false);
        $this->assertFalse($state->consumeStartTag('annotation-xml', '<annotation-xml>', false));
        $this->assertFalse($state->currentElementIsHtmlIntegrationPoint());

        $this->assertTrue($state->startTagUsesHtmlTokenizer('svg', '<svg>'));
        $this->assertTrue($state->consumeStartTag('svg', '<svg>', false));
        $this->assertTrue($state->hasOpenForeignElement());
    }

    #[DataProvider('foreignFontAttributeProvider')]
    public function test_foreign_font_breakout_uses_the_first_browser_parsed_trigger_attribute(
        string $tagText,
        bool $expected,
    ): void {
        $state = new ArtifactPreviewForeignContentState();
        $state->consumeStartTag('svg', '<svg>', false);

        $this->assertSame($expected, $state->startTagUsesHtmlTokenizer('font', $tagText));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function foreignFontAttributeProvider(): iterable
    {
        yield 'absent trigger' => ['<font data-name="example">', false];
        yield 'compact absent trigger' => ['<font data-name>', false];
        yield 'bare color' => ['<font color>', true];
        yield 'empty color before close' => ['<font color=>', true];
        yield 'double quoted color' => ['<font data-name="example" color="red">', true];
        yield 'single quoted face' => ["<font data-name='example' face='serif'>", true];
        yield 'unquoted size' => ['<font data-name=example size=3>', true];
        yield 'mixed whitespace' => ["<font\t\n\f\rdata-name=example\rcolor=red>", true];
        yield 'leading self closing slash parse error' => ['<font / color=red>', true];
        yield 'space after irrelevant attribute name' => ['<font data-name color=red>', true];
        yield 'spaces before irrelevant attribute value' => ['<font data-name   =example color=red>', true];
        yield 'space after bare trigger' => ['<font color >', true];
        yield 'slash after irrelevant attribute name' => ['<font data-name / color=red>', true];
        yield 'close after irrelevant attribute name' => ['<font data-name >', false];
        yield 'spaces before trigger value' => ["<font color = \t red>", true];
        yield 'attribute after self closing slash parse error' => ['<font data-name/ color=red>', true];
        yield 'attribute without separator parse error' => ['<font data-name="example"color=red>', true];
        yield 'slash after quoted irrelevant value' => ['<font data-name="example"/ color=red>', true];
        yield 'self closing without trigger' => ['<font data-name/>', false];
        yield 'unterminated bare trigger' => ['<font color', false];
        yield 'unterminated unquoted trigger' => ['<font color=red', true];
        yield 'unterminated quoted trigger' => ['<font color="red', true];
        yield 'unterminated irrelevant attribute' => ['<font data-name', false];
    }
}
