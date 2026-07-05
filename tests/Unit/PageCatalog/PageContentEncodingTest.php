<?php

declare(strict_types=1);

namespace Tests\Unit\PageCatalog;

use App\Domain\PageCatalog\PageContentEncoding;
use PHPUnit\Framework\TestCase;

final class PageContentEncodingTest extends TestCase
{
    public function test_ordinary_text_with_tabs_and_newlines_is_storable(): void
    {
        $this->assertTrue(PageContentEncoding::isStorable("# Title\n\n\tindented line\r\nunicode: café — ☕\n"));
    }

    public function test_nul_and_other_control_bytes_are_not_storable(): void
    {
        $this->assertTrue(PageContentEncoding::containsControlBytes("text\0more"));
        $this->assertFalse(PageContentEncoding::isStorable("text\0more"));
        $this->assertFalse(PageContentEncoding::isStorable("bell\x07here"));
    }

    public function test_malformed_utf8_is_not_storable(): void
    {
        // A lone 0x80 continuation byte is invalid UTF-8.
        $this->assertFalse(PageContentEncoding::isValidUtf8("bad\x80byte"));
        $this->assertFalse(PageContentEncoding::isStorable("bad\x80byte"));
    }
}
