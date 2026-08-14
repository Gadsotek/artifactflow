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

    private const string INSTALLATION_WORK_BUDGET_KEY = 'artifactflow:image-normalization:work:installation:v1';

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
            $this->reserveBudgets($actorUid, $input->pixels(), $input->workload->units());

            try {
                return $normalize($reservation);
            } catch (Throwable $exception) {
                if (!$reservation->wasDispatched()) {
                    $this->refundBudgets($actorUid, $input->pixels(), $input->workload->units());
                }

                throw $exception;
            }
        } finally {
            if ($reservation->shouldReleaseLease()) {
                $lock->release();
            }
        }
    }

    private function reserveBudgets(string $actorUid, int $pixels, int $workUnits): void
    {
        $userPixelKey = $this->userPixelBudgetKey($actorUid);
        $installationPixelKey = self::INSTALLATION_BUDGET_KEY;
        $userWorkKey = $this->userWorkBudgetKey($actorUid);
        $installationWorkKey = self::INSTALLATION_WORK_BUDGET_KEY;

        if ($this->wouldExceedBudget(
            $userPixelKey,
            $this->normalizationConfiguration->userPixelBudgetPerMinute(),
            $pixels,
        )) {
            throw new ImageNormalizationLimitExceeded($this->retryAfter($userPixelKey));
        }

        if ($this->wouldExceedBudget(
            $installationPixelKey,
            $this->normalizationConfiguration->installationPixelBudgetPerMinute(),
            $pixels,
        )) {
            throw new ImageNormalizationCapacityExceeded($this->retryAfter($installationPixelKey));
        }

        if ($this->wouldExceedBudget(
            $userWorkKey,
            $this->normalizationConfiguration->userWorkBudgetPerMinute(),
            $workUnits,
        )) {
            throw new ImageNormalizationLimitExceeded($this->retryAfter($userWorkKey));
        }

        if ($this->wouldExceedBudget(
            $installationWorkKey,
            $this->normalizationConfiguration->installationWorkBudgetPerMinute(),
            $workUnits,
        )) {
            throw new ImageNormalizationCapacityExceeded($this->retryAfter($installationWorkKey));
        }

        // The shared normalization slot serializes this check-and-increment
        // sequence across every app replica, so none of the budgets can overshoot
        // through concurrent requests.
        $this->rateLimiter->increment($userPixelKey, self::BUDGET_WINDOW_SECONDS, $pixels);
        $this->rateLimiter->increment($installationPixelKey, self::BUDGET_WINDOW_SECONDS, $pixels);
        $this->rateLimiter->increment($userWorkKey, self::BUDGET_WINDOW_SECONDS, $workUnits);
        $this->rateLimiter->increment($installationWorkKey, self::BUDGET_WINDOW_SECONDS, $workUnits);
    }

    private function refundBudgets(string $actorUid, int $pixels, int $workUnits): void
    {
        // The shared slot remains held while all counters are rolled back, so
        // another replica cannot observe or modify a half-refunded reservation.
        $this->rateLimiter->decrement(
            $this->userPixelBudgetKey($actorUid),
            self::BUDGET_WINDOW_SECONDS,
            $pixels,
        );
        $this->rateLimiter->decrement(
            $this->userWorkBudgetKey($actorUid),
            self::BUDGET_WINDOW_SECONDS,
            $workUnits,
        );
        $this->rateLimiter->decrement(
            self::INSTALLATION_WORK_BUDGET_KEY,
            self::BUDGET_WINDOW_SECONDS,
            $workUnits,
        );
        $this->rateLimiter->decrement(
            self::INSTALLATION_BUDGET_KEY,
            self::BUDGET_WINDOW_SECONDS,
            $pixels,
        );
    }

    private function wouldExceedBudget(string $key, int $budget, int $cost): bool
    {
        $attempts = $this->rateLimiter->attempts($key);
        $used = is_numeric($attempts) ? max(0, (int) $attempts) : 0;

        return $cost > $budget - min($used, $budget);
    }

    private function retryAfter(string $key): int
    {
        return max(1, $this->rateLimiter->availableIn($key));
    }

    private function userPixelBudgetKey(string $actorUid): string
    {
        return 'artifactflow:image-normalization:pixels:user:v1:' . hash('sha256', $actorUid);
    }

    private function userWorkBudgetKey(string $actorUid): string
    {
        return 'artifactflow:image-normalization:work:user:v1:' . hash('sha256', $actorUid);
    }

    private function limiterStoreName(): ?string
    {
        $configured = $this->config->get('cache.limiter');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : null;
    }
}
