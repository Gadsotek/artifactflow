<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

final readonly class ProcessorHealthProbeResult
{
    private function __construct(public bool $healthy, public string $detail)
    {
    }

    public static function healthy(string $detail): self
    {
        return new self(true, $detail);
    }

    public static function unhealthy(string $detail): self
    {
        return new self(false, $detail);
    }
}
