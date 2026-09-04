<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\DocxProcessingBusy;
use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use LogicException;

final readonly class DocxProcessingAdmission
{
    public const string SLOT_KEY = 'artifactflow:docx-processing:slot:v1';
    private const int LOCK_EXPIRY_MARGIN_SECONDS = 5;

    public function __construct(
        private Factory $cache,
        private Repository $config,
        private DocxProcessorConfiguration $processorConfiguration,
    ) {
    }

    /** @param Closure(DocxProcessingReservation): DocxConversionResult $process */
    public function run(Closure $process): DocxConversionResult
    {
        $store = $this->cache->store($this->limiterStoreName())->getStore();
        if (!$store instanceof LockProvider) {
            throw new LogicException('The rate limiter cache store must support distributed locks.');
        }

        $expiry = $this->processorConfiguration->timeoutSeconds() + self::LOCK_EXPIRY_MARGIN_SECONDS;
        $lock = $store->lock(self::SLOT_KEY, $expiry);
        if (!$lock->get()) {
            throw new DocxProcessingBusy($expiry);
        }

        $reservation = new DocxProcessingReservation();
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

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }
}
