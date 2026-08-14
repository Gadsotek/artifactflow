<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use Illuminate\Contracts\Config\Repository;
use LogicException;

final readonly class ImageNormalizationConfiguration
{
    public const int MAX_USER_PIXEL_BUDGET_PER_MINUTE = 64 * 1024 * 1024;

    public const int MAX_INSTALLATION_PIXEL_BUDGET_PER_MINUTE = 256 * 1024 * 1024;

    public const int MAX_USER_WORK_BUDGET_PER_MINUTE = 64 * 1024 * 1024;

    public const int MAX_INSTALLATION_WORK_BUDGET_PER_MINUTE = 256 * 1024 * 1024;

    public function __construct(
        private Repository $config,
        private ImageArtifactLimits $imageLimits,
    ) {
    }

    public function userPixelBudgetPerMinute(): int
    {
        $budget = $this->boundedPositiveInt(
            'image_parser.user_pixel_budget_per_minute',
            self::MAX_USER_PIXEL_BUDGET_PER_MINUTE,
        );

        if ($budget < $this->imageLimits->maxUploadPixels()) {
            throw new LogicException('Image normalization user pixel budget must allow one maximum-size upload.');
        }

        return $budget;
    }

    public function installationPixelBudgetPerMinute(): int
    {
        $budget = $this->boundedPositiveInt(
            'image_parser.installation_pixel_budget_per_minute',
            self::MAX_INSTALLATION_PIXEL_BUDGET_PER_MINUTE,
        );

        if ($budget < $this->userPixelBudgetPerMinute()) {
            throw new LogicException(
                'Image normalization installation pixel budget must not be lower than the user budget.',
            );
        }

        return $budget;
    }

    public function userWorkBudgetPerMinute(): int
    {
        $budget = $this->boundedPositiveInt(
            'image_parser.user_work_budget_per_minute',
            self::MAX_USER_WORK_BUDGET_PER_MINUTE,
        );

        if ($budget < self::maximumWorkUnitsForInputBytes($this->imageLimits->maxUploadBytes())) {
            throw new LogicException('Image normalization user work budget must allow one maximum-work upload.');
        }

        return $budget;
    }

    public function installationWorkBudgetPerMinute(): int
    {
        $budget = $this->boundedPositiveInt(
            'image_parser.installation_work_budget_per_minute',
            self::MAX_INSTALLATION_WORK_BUDGET_PER_MINUTE,
        );

        if ($budget < $this->userWorkBudgetPerMinute()) {
            throw new LogicException(
                'Image normalization installation work budget must not be lower than the user budget.',
            );
        }

        return $budget;
    }

    public static function maximumWorkUnitsForInputBytes(int $maxInputBytes): int
    {
        $maximumChunkCount = min(
            ImageArtifactLimits::MAX_PNG_CHUNKS,
            intdiv(max(0, $maxInputBytes - 8), 12),
        );

        return $maxInputBytes
            + min($maxInputBytes, ImageArtifactLimits::MAX_PNG_ANCILLARY_BYTES)
            + ($maximumChunkCount * ImageNormalizationWorkload::STRUCTURE_UNIT_BYTES);
    }

    public static function budgetsAreSafe(
        int $maxUploadPixels,
        int $userBudget,
        int $installationBudget,
    ): bool {
        return $maxUploadPixels >= 1
            && $maxUploadPixels <= ImageArtifactLimits::MAX_UPLOAD_PIXELS
            && $userBudget >= $maxUploadPixels
            && $userBudget <= self::MAX_USER_PIXEL_BUDGET_PER_MINUTE
            && $installationBudget >= $userBudget
            && $installationBudget <= self::MAX_INSTALLATION_PIXEL_BUDGET_PER_MINUTE;
    }

    public static function workBudgetsAreSafe(
        int $maxUploadBytes,
        int $userBudget,
        int $installationBudget,
    ): bool {
        $minimumBudget = self::maximumWorkUnitsForInputBytes($maxUploadBytes);

        return $maxUploadBytes >= 1
            && $maxUploadBytes <= ImageArtifactLimits::MAX_UPLOAD_BYTES
            && $userBudget >= $minimumBudget
            && $userBudget <= self::MAX_USER_WORK_BUDGET_PER_MINUTE
            && $installationBudget >= $userBudget
            && $installationBudget <= self::MAX_INSTALLATION_WORK_BUDGET_PER_MINUTE;
    }

    private function boundedPositiveInt(string $key, int $maximum): int
    {
        $configured = $this->config->get($key);

        if (!PositiveIntegerConfiguration::isIntegerLike($configured)) {
            throw new LogicException(sprintf('Image normalization setting [%s] must be an integer.', $key));
        }

        $value = PositiveIntegerConfiguration::tryFrom($configured);

        if ($value === null || $value > $maximum) {
            throw new LogicException(sprintf(
                'Image normalization setting [%s] must be between 1 and %d.',
                $key,
                $maximum,
            ));
        }

        return $value;
    }
}
