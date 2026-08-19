<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Http\BoundedResponseReader;
use App\Application\Http\BoundedResponseReadFailure;
use Illuminate\Http\Client\Response;

final readonly class ImageParserResponseReader
{
    public function __construct(private BoundedResponseReader $reader)
    {
    }

    public function read(Response $response, int $maximumBytes): string
    {
        try {
            return $this->reader->read($response, $maximumBytes);
        } catch (BoundedResponseReadFailure $exception) {
            throw new ImageParserResponseReadFailure(
                'The image parser response stream could not be read.',
                previous: $exception,
            );
        }
    }
}
