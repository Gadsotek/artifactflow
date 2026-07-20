<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use App\Application\Identity\TwoFactorEnrollmentFreshness;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class TwoFactorEnrollmentFreshnessTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_secret_from_the_current_password_window_is_current(): void
    {
        Carbon::setTestNow('2026-07-19 21:00:00');
        $confirmedAt = now()->subMinute()->getTimestamp();

        $this->assertTrue(app(TwoFactorEnrollmentFreshness::class)->isCurrent(
            now()->subSeconds(30),
            $confirmedAt,
        ));
    }

    public function test_secret_from_before_the_latest_password_confirmation_is_stale(): void
    {
        Carbon::setTestNow('2026-07-19 21:00:00');
        $confirmedAt = now()->subMinute()->getTimestamp();

        $this->assertFalse(app(TwoFactorEnrollmentFreshness::class)->isCurrent(
            now()->subSeconds(61),
            $confirmedAt,
        ));
    }

    public function test_missing_future_and_expired_enrollment_state_fails_closed(): void
    {
        Carbon::setTestNow('2026-07-19 21:00:00');
        $freshness = app(TwoFactorEnrollmentFreshness::class);

        $this->assertFalse($freshness->isCurrent(null, now()->getTimestamp()));
        $this->assertFalse($freshness->isCurrent(now()->addSecond(), now()->getTimestamp()));
        $this->assertFalse($freshness->isCurrent(now()->subSecond(), null));
        $this->assertFalse($freshness->isCurrent(
            now()->subSeconds(181),
            now()->subSeconds(181)->getTimestamp(),
        ));
    }
}
