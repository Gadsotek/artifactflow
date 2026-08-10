<?php

declare(strict_types=1);

namespace Tests\Unit\PageCatalog;

use App\Application\PageCatalog\PageNewness;
use App\Models\Page;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PageNewnessTest extends TestCase
{
    #[DataProvider('creationTimes')]
    public function test_new_label_uses_an_inclusive_one_hour_window(
        string $createdAt,
        bool $expected,
    ): void {
        $now = CarbonImmutable::parse('2026-08-10 12:00:00 UTC');
        $page = new Page();
        $page->forceFill(['created_at' => CarbonImmutable::parse($createdAt)]);

        $this->assertSame($expected, app(PageNewness::class)->isNew($page, $now));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function creationTimes(): iterable
    {
        yield 'created now' => ['2026-08-10 12:00:00 UTC', true];
        yield 'exactly one hour old' => ['2026-08-10 11:00:00 UTC', true];
        yield 'older than one hour' => ['2026-08-10 10:59:59 UTC', false];
        yield 'future timestamp' => ['2026-08-10 12:00:01 UTC', false];
    }
}
