<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

final readonly class DoctorReport
{
    /**
     * @param list<DoctorCheck> $checks
     */
    public function __construct(
        public bool $production,
        public array $checks,
    ) {
    }

    public function passed(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->isFailure()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<DoctorCheck>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, static fn (DoctorCheck $check): bool => $check->isFailure()));
    }
}
