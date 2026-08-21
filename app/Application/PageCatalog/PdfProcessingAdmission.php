<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PdfProcessingBusy;
use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use LogicException;

final readonly class PdfProcessingAdmission
{
    public const string SLOT_KEY = 'artifactflow:pdf-processing:slot:v1';

    private const int LOCK_EXPIRY_MARGIN_SECONDS = 5;

    public function __construct(
        private Factory $cache,
        private Repository $config,
        private PdfProcessorConfiguration $processorConfiguration,
    ) {
    }

    /**
     * @param Closure(PdfProcessingReservation): PdfProcessingResult $process
     */
    public function run(Closure $process): PdfProcessingResult
    {
        $store = $this->cache->store($this->limiterStoreName())->getStore();

        if (!$store instanceof LockProvider) {
            throw new LogicException('The rate limiter cache store must support distributed locks.');
        }

        $lockExpirySeconds = $this->processorConfiguration->timeoutSeconds()
            + self::LOCK_EXPIRY_MARGIN_SECONDS;
        $lock = $store->lock(self::SLOT_KEY, $lockExpirySeconds);

        if (!$lock->get()) {
            throw new PdfProcessingBusy($lockExpirySeconds);
        }

        $reservation = new PdfProcessingReservation();

        try {
            return $process($reservation);
        } finally {
            if ($reservation->shouldReleaseLease()) {
                $lock->release();
            }
        }
    }

    private function limiterStoreName(): ?string
    {
        $configured = $this->config->get('cache.limiter');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : null;
    }
}
