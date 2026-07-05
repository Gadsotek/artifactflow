<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use App\Application\Diagnostics\InstallationPlanner;
use App\Application\Diagnostics\InstallationSecret;
use Tests\TestCase;

final class InstallationPlannerTest extends TestCase
{
    public function test_local_plan_generates_secrets_and_adds_developer_conveniences(): void
    {
        $plan = (new InstallationPlanner())->plan(env: 'local', needsAppKey: true, needsSigningKey: true);

        $this->assertTrue($plan->local);
        $this->assertSame(
            ['app_key', 'signing_key', 'migrate', 'admin', 'demo', 'dev_tools', 'login_url'],
            $plan->stepIds(),
        );
    }

    public function test_local_plan_skips_secret_generation_when_already_present(): void
    {
        $plan = (new InstallationPlanner())->plan(env: 'local', needsAppKey: false, needsSigningKey: false);

        $this->assertSame(['migrate', 'admin', 'demo', 'dev_tools', 'login_url'], $plan->stepIds());
    }

    public function test_test_plan_is_local_semantics_without_demo_and_ends_on_doctor(): void
    {
        $plan = (new InstallationPlanner())->plan(env: 'test', needsAppKey: true, needsSigningKey: true);

        $this->assertTrue($plan->local);
        $this->assertSame(['app_key', 'signing_key', 'migrate', 'admin', 'doctor', 'login_url'], $plan->stepIds());
        $this->assertFalse($plan->hasStep('demo'));
        $this->assertFalse($plan->hasStep('dev_tools'));
    }

    public function test_reverb_key_generation_is_added_only_when_realtime_is_requested(): void
    {
        $plan = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            wantsReverb: true,
        );

        $this->assertSame(
            ['reverb_keys', 'migrate', 'admin', 'demo', 'dev_tools', 'login_url'],
            $plan->stepIds(),
        );
    }

    public function test_production_plan_generates_missing_secrets_skips_demo_and_ends_on_doctor(): void
    {
        $plan = (new InstallationPlanner())->plan(env: 'production', needsAppKey: true, needsSigningKey: true);

        $this->assertFalse($plan->local);
        $this->assertSame(['app_key', 'signing_key', 'migrate', 'admin', 'doctor', 'login_url'], $plan->stepIds());
        $this->assertTrue($plan->hasStep('app_key'));
        $this->assertFalse($plan->hasStep('demo'));
        $this->assertFalse($plan->hasStep('dev_tools'));
    }

    public function test_installation_secret_detects_missing_placeholder_and_weak_values(): void
    {
        $this->assertTrue(InstallationSecret::isMissing(''));
        $this->assertTrue(InstallationSecret::isMissing('base64:replace-with-a-real-key'));
        $this->assertTrue(InstallationSecret::isMissing('base64:' . base64_encode('too-short')));
        $this->assertFalse(InstallationSecret::isMissing('base64:' . base64_encode(str_repeat('a', 32))));
        $this->assertFalse(InstallationSecret::isMissing(str_repeat('x', 40)));
    }
}
