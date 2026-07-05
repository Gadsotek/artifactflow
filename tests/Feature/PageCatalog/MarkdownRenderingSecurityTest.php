<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\MarkdownPageRenderer;
use Tests\TestCase;

final class MarkdownRenderingSecurityTest extends TestCase
{
    public function test_safe_markdown_links_open_in_a_new_tab_without_opener_access(): void
    {
        $html = app(MarkdownPageRenderer::class)->render(
            '[runbook](https://example.test/runbook)',
        );

        $this->assertStringContainsString(
            '<a href="https://example.test/runbook" target="_blank" rel="noopener noreferrer">runbook</a>',
            $html,
        );
    }

    public function test_markdown_rendering_strips_raw_html_and_control_character_javascript_links(): void
    {
        $html = app(MarkdownPageRenderer::class)->render(
            "<script>alert(1)</script><img src=x onerror=alert(1)>\n\n"
            . "[tab](&Tab;javascript:alert(1))\n\n"
            . "[newline](&NewLine;javascript:alert(1))\n\n"
            . "[safe](https://example.test/runbook)",
        );

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('href="%09javascript:', $html);
        $this->assertStringNotContainsString('href="%0Ajavascript:', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('href="https://example.test/runbook"', $html);
    }

    public function test_markdown_rendering_preserves_utf8_and_does_not_mojibake(): void
    {
        $html = app(MarkdownPageRenderer::class)->render(
            "# café — 日本語 😀\n\nStrategy runbook · with an em—dash and smart “quotes”.",
        );

        $this->assertStringContainsString('café', $html);
        $this->assertStringContainsString('日本語', $html);
        $this->assertStringContainsString('😀', $html);
        $this->assertStringNotContainsString('Ã', $html);
        $this->assertStringNotContainsString('â€', $html);
    }

    public function test_markdown_rendering_strips_unsafe_image_sources(): void
    {
        $html = app(MarkdownPageRenderer::class)->render(
            "![tab](&Tab;javascript:alert(1))\n\n"
            . "![entity](jav&#x61;script:alert(1))\n\n"
            . "![safe](https://example.test/diagram.png)",
        );

        $this->assertStringNotContainsString('src="%09javascript:', $html);
        $this->assertStringNotContainsString('src="jav&amp;#x61;script:', $html);
        $this->assertStringNotContainsString('src="javascript:', $html);
        $this->assertStringContainsString('src="https://example.test/diagram.png"', $html);
    }

    public function test_markdown_rendering_allows_safe_raster_data_images_only_for_image_sources(): void
    {
        $html = app(MarkdownPageRenderer::class)->render(
            "![pixel](data:image/png;base64,iVBORw0KGgo=)\n\n"
            . "![jpeg](data:image/jpeg;base64,/9j/4AAQSkZJRg==)\n\n"
            . "![svg](data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+)\n\n"
            . "![html](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)\n\n"
            . "[data link](data:image/png;base64,iVBORw0KGgo=)\n\n"
            . "![split scheme](java&#x09;script:alert(1))",
        );

        $this->assertStringContainsString('src="data:image/png;base64,iVBORw0KGgo="', $html);
        $this->assertStringContainsString('src="data:image/jpeg;base64,/9j/4AAQSkZJRg=="', $html);
        $this->assertStringNotContainsString('data:image/svg+xml', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringNotContainsString('href="data:', $html);
        $this->assertStringNotContainsString('javascript:alert(1)', $html);
    }
}
