<?php

declare(strict_types=1);

namespace App\Application\Administration;

final readonly class InstallationLimitViewItem
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public int $value,
        public string $displayValue,
        public string $unit,
        public int $maxValue,
        public string $maxDisplayValue,
        public string $displayAmount = '',
        public string $displayUnit = '',
    ) {
    }

    public function usesByteUnits(): bool
    {
        return $this->unit === 'bytes';
    }
}
