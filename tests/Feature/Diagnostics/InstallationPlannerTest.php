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
        $plan = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: true,
            needsSigningKey: true,
            needsImageParserSecret: true,
        );

        $this->assertTrue($plan->local);
        $this->assertSame(
            ['app_key', 'signing_key', 'image_parser_secret', 'migrate', 'admin', 'demo', 'dev_tools', 'doctor', 'login_url'],
            $plan->stepIds(),
        );
    }

    public function test_local_plan_skips_secret_generation_when_already_present(): void
    {
        $plan = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            needsImageParserSecret: false,
        );

        $this->assertSame(['migrate', 'admin', 'demo', 'dev_tools', 'doctor', 'login_url'], $plan->stepIds());
    }

    public function test_test_plan_is_local_semantics_without_demo_and_ends_on_doctor(): void
    {
        $plan = (new InstallationPlanner())->plan(
            env: 'test',
            needsAppKey: true,
            needsSigningKey: true,
            needsImageParserSecret: true,
        );

        $this->assertTrue($plan->local);
        $this->assertSame(
            ['app_key', 'signing_key', 'image_parser_secret', 'migrate', 'admin', 'doctor', 'login_url'],
            $plan->stepIds(),
        );
        $this->assertFalse($plan->hasStep('demo'));
        $this->assertFalse($plan->hasStep('dev_tools'));
    }

    public function test_reverb_key_generation_is_added_only_when_realtime_is_requested(): void
    {
        $plan = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            needsImageParserSecret: false,
            wantsReverb: true,
        );

        $this->assertSame(
            ['reverb_keys', 'migrate', 'admin', 'demo', 'dev_tools', 'doctor', 'login_url'],
            $plan->stepIds(),
        );
    }

    public function test_pdf_secret_generation_is_added_only_when_pdf_is_requested_and_the_secret_is_missing(): void
    {
        $requested = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            needsImageParserSecret: false,
            wantsPdf: true,
            needsPdfProcessorSecret: true,
        );
        $notRequested = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            needsImageParserSecret: false,
            wantsPdf: false,
            needsPdfProcessorSecret: true,
        );
        $alreadyProvisioned = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            needsImageParserSecret: false,
            wantsPdf: true,
            needsPdfProcessorSecret: false,
        );

        $this->assertSame(
            ['pdf_processor_secret', 'migrate', 'admin', 'demo', 'dev_tools', 'doctor', 'login_url'],
            $requested->stepIds(),
        );
        $this->assertFalse($notRequested->hasStep('pdf_processor_secret'));
        $this->assertFalse($alreadyProvisioned->hasStep('pdf_processor_secret'));
    }

    public function test_xlsx_secret_generation_is_added_only_when_xlsx_is_requested_and_the_secret_is_missing(): void
    {
        $requested = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            needsImageParserSecret: false,
            wantsXlsx: true,
            needsXlsxProcessorSecret: true,
        );
        $notRequested = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            needsImageParserSecret: false,
            wantsXlsx: false,
            needsXlsxProcessorSecret: true,
        );
        $alreadyProvisioned = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            needsImageParserSecret: false,
            wantsXlsx: true,
            needsXlsxProcessorSecret: false,
        );

        $this->assertSame(
            ['xlsx_processor_secret', 'migrate', 'admin', 'demo', 'dev_tools', 'doctor', 'login_url'],
            $requested->stepIds(),
        );
        $this->assertFalse($notRequested->hasStep('xlsx_processor_secret'));
        $this->assertFalse($alreadyProvisioned->hasStep('xlsx_processor_secret'));
    }

    public function test_docx_secret_generation_is_added_only_when_docx_is_requested_and_the_secret_is_missing(): void
    {
        $requested = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            needsImageParserSecret: false,
            wantsPdf: true,
            wantsDocx: true,
            needsDocxProcessorSecret: true,
        );
        $notRequested = (new InstallationPlanner())->plan(
            env: 'local',
            needsAppKey: false,
            needsSigningKey: false,
            needsImageParserSecret: false,
            wantsDocx: false,
            needsDocxProcessorSecret: true,
        );

        $this->assertTrue($requested->hasStep('docx_processor_secret'));
        $this->assertFalse($notRequested->hasStep('docx_processor_secret'));
    }

    public function test_production_plan_never_generates_secrets_and_ends_on_doctor(): void
    {
        $plan = (new InstallationPlanner())->plan(
            env: 'production',
            needsAppKey: true,
            needsSigningKey: true,
            needsImageParserSecret: true,
        );

        $this->assertFalse($plan->local);
        $this->assertSame(['migrate', 'admin', 'doctor', 'login_url'], $plan->stepIds());
        $this->assertFalse($plan->hasStep('app_key'));
        $this->assertFalse($plan->hasStep('signing_key'));
        $this->assertFalse($plan->hasStep('image_parser_secret'));
        $this->assertFalse($plan->hasStep('demo'));
        $this->assertFalse($plan->hasStep('dev_tools'));
    }

    public function test_installation_secret_detects_missing_placeholder_and_weak_values(): void
    {
        $this->assertTrue(InstallationSecret::isMissing(''));
        $this->assertTrue(InstallationSecret::isMissing('base64:replace-with-a-real-key'));
        $this->assertTrue(InstallationSecret::isMissing('base64:' . base64_encode('too-short')));
        $this->assertTrue(InstallationSecret::isMissing('artifact-preview-test-signing-key'));
        $this->assertTrue(InstallationSecret::isMissing('artifactflow-local-pdf-processor-secret-not-for-production'));
        $this->assertTrue(InstallationSecret::isMissing('artifactflow-local-xlsx-processor-secret-not-for-production'));
        $this->assertTrue(InstallationSecret::isMissing('artifactflow-local-docx-processor-secret-not-for-production'));
        $this->assertFalse(InstallationSecret::isMissing('base64:' . base64_encode(str_repeat('a', 32))));
        $this->assertFalse(InstallationSecret::isMissing(str_repeat('x', 40)));
    }

    public function test_installation_secret_requires_replacement_when_a_strong_value_reuses_another_boundary(): void
    {
        $candidate = 'base64:' . base64_encode(str_repeat('x', 32));

        $this->assertTrue(InstallationSecret::needsReplacement($candidate, [$candidate]));
        $this->assertTrue(InstallationSecret::needsReplacement(
            $candidate,
            ['base64:' . base64_encode(str_repeat('x', 32))],
        ));
        $this->assertFalse(InstallationSecret::needsReplacement(
            $candidate,
            ['base64:' . base64_encode(str_repeat('y', 32))],
        ));
    }
}
