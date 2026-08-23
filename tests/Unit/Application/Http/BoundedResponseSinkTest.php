<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Http;

use App\Application\Http\BoundedResponseReadFailure;
use App\Application\Http\BoundedResponseSink;
use PHPUnit\Framework\TestCase;

final class BoundedResponseSinkTest extends TestCase
{
    public function test_it_refuses_to_buffer_more_than_the_configured_response_limit(): void
    {
        $sink = new BoundedResponseSink(4);

        $this->assertSame(3, $sink->write('abc'));

        $this->expectException(BoundedResponseReadFailure::class);

        $sink->write('de');
    }

    public function test_it_preserves_the_bounded_bytes_for_normal_response_reading(): void
    {
        $sink = new BoundedResponseSink(4);

        $this->assertSame(4, $sink->write('data'));
        $sink->rewind();

        $this->assertSame('data', $sink->getContents());
    }
}
