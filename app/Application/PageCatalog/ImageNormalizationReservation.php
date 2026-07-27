<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use LogicException;

final class ImageNormalizationReservation
{
    private bool $dispatched = false;

    private bool $retainLeaseUntilExpiry = false;

    public function markDispatched(): void
    {
        $this->dispatched = true;
    }

    public function wasDispatched(): bool
    {
        return $this->dispatched;
    }

    public function retainLeaseUntilExpiry(): void
    {
        if (!$this->dispatched) {
            throw new LogicException('A normalization lease can be retained only after dispatch.');
        }

        $this->retainLeaseUntilExpiry = true;
    }

    public function shouldReleaseLease(): bool
    {
        return !$this->retainLeaseUntilExpiry;
    }
}
