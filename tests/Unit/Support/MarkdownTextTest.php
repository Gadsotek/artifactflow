<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MarkdownText;
use PHPUnit\Framework\TestCase;

final class MarkdownTextTest extends TestCase
{
    public function test_link_and_emphasis_syntax_is_backslash_escaped(): void
    {
        $this->assertSame(
            'Eve \[click here\]\(https\:\/\/phish\.example\)',
            MarkdownText::escapeInline('Eve [click here](https://phish.example)'),
        );

        $this->assertSame('\*\*bold\*\* and \_em\_ and \`code\`', MarkdownText::escapeInline('**bold** and _em_ and `code`'));
    }

    public function test_html_special_characters_are_left_for_blade_to_escape(): void
    {
        // These are html-escaped by Blade downstream; escaping them here too would
        // collide with the entity Blade produces, so they pass through unchanged.
        $this->assertSame('<script>', MarkdownText::escapeInline('<script>'));
        $this->assertSame('a & b "c" \'d\'', MarkdownText::escapeInline('a & b "c" \'d\''));
    }

    public function test_plain_text_and_unicode_are_unchanged(): void
    {
        $this->assertSame('Platform Team café', MarkdownText::escapeInline('Platform Team café'));
    }
}
