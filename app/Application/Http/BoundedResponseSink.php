<?php

declare(strict_types=1);

namespace App\Application\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use LogicException;
use Psr\Http\Message\StreamInterface;

final class BoundedResponseSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private const int MEMORY_BYTES = 1024 * 1024;

    private StreamInterface $stream;

    public function __construct(private readonly int $maximumBytes)
    {
        if ($maximumBytes < 1) {
            throw new LogicException('The response byte limit must be positive.');
        }

        $this->stream = Utils::streamFor(Utils::tryFopen(
            sprintf('php://temp/maxmemory:%d', self::MEMORY_BYTES),
            'w+b',
        ));
    }

    public function write(string $string): int
    {
        $size = $this->stream->getSize();

        if ($size === null || strlen($string) > $this->maximumBytes - $size) {
            throw new BoundedResponseReadFailure('The response exceeds its byte limit.');
        }

        return $this->stream->write($string);
    }
}
