<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\XlsxProcessingBusy;
use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use LogicException;

final readonly class XlsxProcessingAdmission
{
    public const string SLOT_KEY = 'artifactflow:xlsx-processing:slot:v1';

    private const int LOCK_EXPIRY_MARGIN_SECONDS = 5;

    public function __construct(
        private Factory $cache,
        private Repository $config,
        private XlsxProcessorConfiguration $processorConfiguration,
    ) {
    }

    /**
     * @param Closure(XlsxProcessingReservation): XlsxProcessingResult $process
     */
    public function run(Closure $process): XlsxProcessingResult
    {
        $store = $this->cache->store($this->limiterStoreName())->getStore();

        if (!$store instanceof LockProvider) {
            throw new LogicException('The rate limiter cache store must support distributed locks.');
        }

        $lockExpirySeconds = $this->processorConfiguration->timeoutSeconds()
            + self::LOCK_EXPIRY_MARGIN_SECONDS;
        $lock = $store->lock(self::SLOT_KEY, $lockExpirySeconds);

        if (!$lock->get()) {
            throw new XlsxProcessingBusy($lockExpirySeconds);
        }

        $reservation = new XlsxProcessingReservation();

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
