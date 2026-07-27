<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\ImageNormalizationBusy;
use App\Domain\PageCatalog\ImageNormalizationCapacityExceeded;
use App\Domain\PageCatalog\ImageNormalizationLimitExceeded;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use LogicException;
use Throwable;

final readonly class ImageNormalizationAdmission
{
    public const string SLOT_KEY = 'artifactflow:image-normalization:slot:v1';

    private const string INSTALLATION_BUDGET_KEY = 'artifactflow:image-normalization:pixels:installation:v1';

    private const int BUDGET_WINDOW_SECONDS = 60;

    private const int LOCK_EXPIRY_MARGIN_SECONDS = 5;

    public function __construct(
        private Factory $cache,
        private Repository $config,
        private RateLimiter $rateLimiter,
        private ImageNormalizationConfiguration $normalizationConfiguration,
        private ImageParserConfiguration $parserConfiguration,
    ) {
    }

    /**
     * @param Closure(ImageNormalizationReservation): NormalizedRasterImage $normalize
     */
    public function run(string $actorUid, RasterImageInfo $input, Closure $normalize): NormalizedRasterImage
    {
        if ($actorUid === '') {
            throw new LogicException('Image normalization requires an actor UID.');
        }

        $store = $this->cache->store($this->limiterStoreName())->getStore();

        if (!$store instanceof LockProvider) {
            throw new LogicException('The rate limiter cache store must support distributed locks.');
        }

        $lockExpirySeconds = $this->parserConfiguration->timeoutSeconds() + self::LOCK_EXPIRY_MARGIN_SECONDS;
        $lock = $store->lock(self::SLOT_KEY, $lockExpirySeconds);

        if (!$lock->get()) {
            throw new ImageNormalizationBusy($lockExpirySeconds);
        }

        $reservation = new ImageNormalizationReservation();

        try {
            $this->reservePixelBudget($actorUid, $input->pixels());

            try {
                return $normalize($reservation);
            } catch (Throwable $exception) {
                if (!$reservation->wasDispatched()) {
                    $this->refundPixelBudget($actorUid, $input->pixels());
                }

                throw $exception;
            }
        } finally {
            if ($reservation->shouldReleaseLease()) {
                $lock->release();
            }
        }
    }

    private function reservePixelBudget(string $actorUid, int $pixels): void
    {
        $userKey = $this->userBudgetKey($actorUid);
        $installationKey = self::INSTALLATION_BUDGET_KEY;
        $userBudget = $this->normalizationConfiguration->userPixelBudgetPerMinute();
        $installationBudget = $this->normalizationConfiguration->installationPixelBudgetPerMinute();

        if ($this->wouldExceedBudget($userKey, $userBudget, $pixels)) {
            throw new ImageNormalizationLimitExceeded($this->retryAfter($userKey));
        }

        if ($this->wouldExceedBudget($installationKey, $installationBudget, $pixels)) {
            throw new ImageNormalizationCapacityExceeded($this->retryAfter($installationKey));
        }

        // The shared normalization slot serializes this check-and-increment
        // sequence across every app replica, so neither budget can overshoot
        // through concurrent requests.
        $this->rateLimiter->increment($userKey, self::BUDGET_WINDOW_SECONDS, $pixels);
        $this->rateLimiter->increment($installationKey, self::BUDGET_WINDOW_SECONDS, $pixels);
    }

    private function refundPixelBudget(string $actorUid, int $pixels): void
    {
        // The shared slot remains held while both counters are rolled back, so
        // another replica cannot observe or modify a half-refunded reservation.
        $this->rateLimiter->decrement(
            $this->userBudgetKey($actorUid),
            self::BUDGET_WINDOW_SECONDS,
            $pixels,
        );
        $this->rateLimiter->decrement(
            self::INSTALLATION_BUDGET_KEY,
            self::BUDGET_WINDOW_SECONDS,
            $pixels,
        );
    }

    private function wouldExceedBudget(string $key, int $budget, int $pixels): bool
    {
        $attempts = $this->rateLimiter->attempts($key);
        $used = is_numeric($attempts) ? max(0, (int) $attempts) : 0;

        return $pixels > $budget - min($used, $budget);
    }

    private function retryAfter(string $key): int
    {
        return max(1, $this->rateLimiter->availableIn($key));
    }

    private function userBudgetKey(string $actorUid): string
    {
        return 'artifactflow:image-normalization:pixels:user:v1:' . hash('sha256', $actorUid);
    }

    private function limiterStoreName(): ?string
    {
        $configured = $this->config->get('cache.limiter');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : null;
    }
}
