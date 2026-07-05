<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

final readonly class DoctorCheck
{
    public function __construct(
        public string $id,
        public string $label,
        public DoctorCheckStatus $status,
        public string $detail,
    ) {
    }

    public function isFailure(): bool
    {
        return $this->status === DoctorCheckStatus::Fail;
    }
}
