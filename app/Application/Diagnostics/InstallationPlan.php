<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

final readonly class InstallationPlan
{
    /**
     * @param list<InstallationStep> $steps
     */
    public function __construct(
        public bool $local,
        public array $steps,
    ) {
    }

    public function hasStep(string $id): bool
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function stepIds(): array
    {
        return array_map(static fn (InstallationStep $step): string => $step->id, $this->steps);
    }
}
