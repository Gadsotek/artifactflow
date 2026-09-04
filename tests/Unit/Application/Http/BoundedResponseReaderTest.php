<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Http;

use App\Application\Http\BoundedResponseReader;
use App\Application\Http\BoundedResponseReadFailure;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class BoundedResponseReaderTest extends TestCase
{
    public function test_it_reads_identity_responses_with_consistent_or_omitted_lengths(): void
    {
        $reader = new BoundedResponseReader();

        $this->assertSame('data', $reader->read($this->response('data'), 4));
        $this->assertSame('data', $reader->read($this->response('data', [
            'Content-Encoding' => 'identity',
            'Content-Length' => '4',
        ]), 4));
    }

    public function test_it_rejects_non_positive_limits_before_reading(): void
    {
        $this->expectException(LogicException::class);

        (new BoundedResponseReader())->read($this->response(''), 0);
    }

    public function test_it_rejects_encoded_oversized_invalid_and_inconsistent_responses(): void
    {
        $reader = new BoundedResponseReader();

        foreach ([
            [$this->response('data', ['Content-Encoding' => 'gzip']), 4],
            [$this->response('data', ['Content-Encoding' => ['identity', 'gzip']]), 4],
            [$this->response('data', ['Content-Length' => 'invalid']), 4],
            [$this->response('data', ['Content-Length' => ['4', '4']]), 4],
            [$this->response('data', ['Content-Length' => '5']), 4],
            [$this->response('data', ['Content-Length' => '3']), 4],
            [$this->response('oversized'), 4],
        ] as [$response, $maximumBytes]) {
            try {
                $reader->read($response, $maximumBytes);
                $this->fail('An invalid response must fail bounded reading.');
            } catch (BoundedResponseReadFailure) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_maps_stalled_unreadable_and_unclosable_streams_to_bounded_failures(): void
    {
        $stalled = FnStream::decorate(Utils::streamFor(''), [
            'getSize' => static fn (): ?int => null,
            'eof' => static fn (): bool => false,
            'read' => static fn (int $length): string => '',
        ]);
        $unreadable = FnStream::decorate(Utils::streamFor('data'), [
            'getSize' => static function (): never {
                throw new RuntimeException('size unavailable');
            },
        ]);
        $closeAttempts = 0;
        $unclosable = FnStream::decorate(Utils::streamFor(''), [
            'close' => static function () use (&$closeAttempts): void {
                if ($closeAttempts++ === 0) {
                    throw new RuntimeException('close failed');
                }
            },
        ]);

        foreach ([$stalled, $unreadable, $unclosable] as $stream) {
            try {
                (new BoundedResponseReader())->read($this->response($stream), 4);
                $this->fail('A broken response stream must fail bounded reading.');
            } catch (BoundedResponseReadFailure) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function response(string|StreamInterface $body, array $headers = []): Response
    {
        return new Response(new PsrResponse(200, $headers, $body));
    }
}
