<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class TwoFactorSecretDiagnosis
{
    public function __construct(
        public int $checked,
        public int $readable,
        public int $unreadable,
    ) {
    }

    public function exitCode(): int
    {
        return $this->unreadable > 0 ? 1 : 0;
    }

    /**
     * @return array{checked: int, readable: int, unreadable: int}
     */
    public function toArray(): array
    {
        return [
            'checked' => $this->checked,
            'readable' => $this->readable,
            'unreadable' => $this->unreadable,
        ];
    }
}
