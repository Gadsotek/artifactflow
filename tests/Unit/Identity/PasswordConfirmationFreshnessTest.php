<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use App\Application\Identity\PasswordConfirmationFreshness;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class PasswordConfirmationFreshnessTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_enrollment_uses_its_shorter_dedicated_window(): void
    {
        Carbon::setTestNow('2026-07-19 20:00:00');
        config([
            'auth.password_timeout' => 900,
            'auth.two_factor_enrollment_password_timeout' => 180,
        ]);
        $confirmedAt = now()->subSeconds(181)->getTimestamp();
        $freshness = app(PasswordConfirmationFreshness::class);

        $this->assertTrue($freshness->isFresh($confirmedAt));
        $this->assertFalse($freshness->isFreshForTwoFactorEnrollment($confirmedAt));
        $this->assertSame($confirmedAt + 180, $freshness->expiresAtForTwoFactorEnrollment($confirmedAt));
    }

    public function test_malformed_and_future_confirmation_markers_fail_closed(): void
    {
        Carbon::setTestNow('2026-07-19 20:00:00');
        $freshness = app(PasswordConfirmationFreshness::class);

        foreach ([null, '', 'not-a-time', [], now()->addSecond()->getTimestamp()] as $invalid) {
            $this->assertFalse($freshness->isFresh($invalid));
            $this->assertFalse($freshness->isFreshForTwoFactorEnrollment($invalid));
            $this->assertNull($freshness->expiresAtForTwoFactorEnrollment($invalid));
        }
    }

    public function test_numeric_string_markers_and_minimum_timeout_are_supported(): void
    {
        Carbon::setTestNow('2026-07-19 20:00:00');
        config(['auth.two_factor_enrollment_password_timeout' => 1]);
        $confirmedAt = (string) now()->subSeconds(59)->getTimestamp();
        $freshness = app(PasswordConfirmationFreshness::class);

        $this->assertTrue($freshness->isFreshForTwoFactorEnrollment($confirmedAt));
        $this->assertSame((int) $confirmedAt + 60, $freshness->expiresAtForTwoFactorEnrollment($confirmedAt));

        Carbon::setTestNow(now()->addSecond());
        $this->assertFalse($freshness->isFreshForTwoFactorEnrollment($confirmedAt));
    }
}
