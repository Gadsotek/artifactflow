<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

final readonly class InstallationStep
{
    public function __construct(
        public string $id,
        public string $description,
    ) {
    }
}
