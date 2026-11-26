<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\PassageRetrieval\PassageMaintenance;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;
use Throwable;

final class PurgePassageGeneration implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;

    public int $uniqueFor = 180;
    public int $timeout = 45;
    public int $backoff = 15;

    public function __construct(public readonly int $generation)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->generation;
    }

    public function handle(PassageMaintenance $maintenance): void
    {
        try {
            $maintenance->purge($this->generation);
        } catch (Throwable) {
            // Failed-job storage must never receive a storage path or SQL bindings.
            throw new RuntimeException('Passage maintenance was interrupted.');
        }
    }
}
