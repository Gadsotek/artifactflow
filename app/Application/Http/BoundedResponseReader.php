<?php

declare(strict_types=1);

namespace App\Application\Http;

use Illuminate\Http\Client\Response;
use LogicException;
use Psr\Http\Message\StreamInterface;
use Throwable;

final readonly class BoundedResponseReader
{
    private const int CHUNK_BYTES = 64 * 1024;

    public function read(Response $response, int $maximumBytes): string
    {
        if ($maximumBytes < 1) {
            throw new LogicException('The response byte limit must be positive.');
        }

        try {
            $psrResponse = $response->toPsrResponse();
            $this->assertIdentityEncoding(array_values($psrResponse->getHeader('Content-Encoding')));
            $declaredLength = $this->declaredContentLength(
                array_values($psrResponse->getHeader('Content-Length')),
                $maximumBytes,
            );
            $body = $psrResponse->getBody();
            $knownSize = $body->getSize();

            if ($knownSize !== null && $knownSize > $maximumBytes) {
                throw new BoundedResponseReadFailure('The response exceeds its byte limit.');
            }

            $chunks = [];
            $readBytes = 0;

            while (!$body->eof()) {
                $remainingWithSentinel = ($maximumBytes + 1) - $readBytes;

                if ($remainingWithSentinel < 1) {
                    throw new BoundedResponseReadFailure('The response exceeds its byte limit.');
                }

                $chunk = $body->read(min(self::CHUNK_BYTES, $remainingWithSentinel));

                if ($chunk === '') {
                    if ($this->streamEnded($body)) {
                        break;
                    }

                    throw new BoundedResponseReadFailure('The response stream stalled.');
                }

                $readBytes += strlen($chunk);

                if ($readBytes > $maximumBytes) {
                    throw new BoundedResponseReadFailure('The response exceeds its byte limit.');
                }

                $chunks[] = $chunk;
            }

            if ($declaredLength !== null && $readBytes !== $declaredLength) {
                throw new BoundedResponseReadFailure('The response length is inconsistent.');
            }

            return implode('', $chunks);
        } catch (BoundedResponseReadFailure $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BoundedResponseReadFailure(
                'The response stream could not be read.',
                previous: $exception,
            );
        } finally {
            try {
                $response->close();
            } catch (Throwable $exception) {
                throw new BoundedResponseReadFailure(
                    'The response stream could not be closed.',
                    previous: $exception,
                );
            }
        }
    }

    /**
     * @phpstan-impure
     */
    private function streamEnded(StreamInterface $stream): bool
    {
        return $stream->eof();
    }

    /**
     * @param list<string> $encodings
     */
    private function assertIdentityEncoding(array $encodings): void
    {
        if ($encodings === []) {
            return;
        }

        if (count($encodings) !== 1 || strtolower(trim($encodings[0])) !== 'identity') {
            throw new BoundedResponseReadFailure('Encoded responses are not accepted.');
        }
    }

    /**
     * @param list<string> $lengths
     */
    private function declaredContentLength(array $lengths, int $maximumBytes): ?int
    {
        if ($lengths === []) {
            return null;
        }

        if (count($lengths) !== 1 || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $lengths[0]) !== 1) {
            throw new BoundedResponseReadFailure('The response length is invalid.');
        }

        $declaredLength = ltrim($lengths[0], '0');
        $declaredLength = $declaredLength === '' ? '0' : $declaredLength;
        $maximum = (string) $maximumBytes;

        if (
            strlen($declaredLength) > strlen($maximum)
            || (strlen($declaredLength) === strlen($maximum) && strcmp($declaredLength, $maximum) > 0)
        ) {
            throw new BoundedResponseReadFailure('The response exceeds its byte limit.');
        }

        return (int) $declaredLength;
    }
}
