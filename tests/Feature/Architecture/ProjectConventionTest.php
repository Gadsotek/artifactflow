<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

final class ProjectConventionTest extends TestCase
{
    public function test_controllers_delegate_validation_and_transactions_to_application_boundaries(): void
    {
        foreach ($this->controllerFiles() as $controllerFile) {
            $source = $this->source($controllerFile);

            $this->assertStringNotContainsString(
                '->validate(',
                $source,
                sprintf('%s must use a dedicated Form Request.', $controllerFile),
            );
        }

        $joinController = $this->source(app_path('Http/Controllers/WorkspaceInvitationJoinController.php'));
        $this->assertStringNotContainsString('Facades\\DB', $joinController);
        $this->assertStringNotContainsString('DB::transaction', $joinController);
    }

    public function test_views_receive_domain_options_instead_of_enumerating_domain_types(): void
    {
        $dashboard = $this->source(resource_path('views/dashboard.blade.php'));

        $this->assertStringNotContainsString(
            '\\App\\Domain\\Identity\\WorkspaceRole::cases()',
            $dashboard,
        );
    }

    public function test_page_access_revocation_journaling_is_shared_by_all_workflows(): void
    {
        foreach ([
            app_path('Application/Identity/RemoveWorkspaceMember.php'),
            app_path('Application/Identity/ExcludeInheritedWorkspaceMember.php'),
        ] as $membershipAuthorityLossHandlerFile) {
            $source = $this->source($membershipAuthorityLossHandlerFile);

            $this->assertStringContainsString('WorkspaceMemberAuthorityRetirement', $source);
        }

        foreach ([
            app_path('Application/Identity/WorkspaceMemberAuthorityRetirement.php'),
            app_path('Application/Identity/ReparentWorkspace.php'),
        ] as $authorityLossHandlerFile) {
            $source = $this->source($authorityLossHandlerFile);

            $this->assertStringContainsString('DirectUserPageAccessGrantRevoker', $source);
        }

        foreach ([
            app_path('Application/PageCatalog/DirectUserPageAccessGrantRevoker.php'),
            app_path('Application/PageCatalog/RevokePageAccess.php'),
        ] as $revocationWorkflowFile) {
            $source = $this->source($revocationWorkflowFile);

            $this->assertStringContainsString('PageAccessGrantRevocationJournal', $source);
        }
    }

    public function test_rich_editor_does_not_use_deprecated_exec_command(): void
    {
        $editor = $this->source(resource_path('js/rich-markdown-editor.js'));

        $this->assertStringNotContainsString('document.execCommand', $editor);
    }

    public function test_removed_registration_toggle_is_not_advertised(): void
    {
        foreach ([base_path('.env.example'), base_path('docker-compose.yml')] as $configurationFile) {
            $this->assertStringNotContainsString('REGISTRATION_ENABLED', $this->source($configurationFile));
        }
    }

    public function test_admin_step_up_environment_templates_use_the_two_factor_timeout(): void
    {
        foreach ([base_path('.env.example'), base_path('.env.production.example')] as $configurationFile) {
            $source = $this->source($configurationFile);

            $this->assertStringContainsString('AUTH_ADMIN_TWO_FACTOR_TIMEOUT=900', $source);
            $this->assertStringNotContainsString('AUTH_ADMIN_PASSWORD_TIMEOUT', $source);
        }

        $this->assertStringContainsString(
            "env('AUTH_ADMIN_PASSWORD_TIMEOUT', 900)",
            $this->source(config_path('auth.php')),
            'Existing deployments retain their configured step-up duration during the rename.',
        );
        $this->assertStringContainsString(
            "'ADMIN_TWO_FACTOR_RATE_LIMIT_PER_MINUTE',\n        env('ADMIN_STEP_UP_RATE_LIMIT_PER_MINUTE', 5)",
            $this->source(config_path('rate_limits.php')),
            'Existing deployments retain their configured admin confirmation rate during the rename.',
        );
    }

    public function test_draft_preview_has_a_route_specific_edge_body_limit(): void
    {
        $caddyfile = $this->source(base_path('docker/Caddyfile'));

        $this->assertStringContainsString(
            '@artifactDraftPreview path /artifact-previews/draft',
            $caddyfile,
        );
        $this->assertStringContainsString(
            'max_size {$ARTIFACT_DRAFT_PREVIEW_MAX_BODY:6MB}',
            $caddyfile,
        );
    }

    public function test_caddy_sandboxes_responses_that_do_not_reach_php(): void
    {
        $caddyfile = $this->source(base_path('docker/Caddyfile'));
        $errorPolicy = $this->source(base_path('docker/Caddyfile.security-errors'));

        $this->assertStringContainsString(
            '?Content-Security-Policy "default-src \'none\'; sandbox"',
            $caddyfile,
            'The conditional prefix must preserve stricter PHP-generated artifact policies.',
        );
        $this->assertStringContainsString('import Caddyfile.security-errors', $caddyfile);
        $this->assertStringContainsString('handle_errors {', $errorPolicy);
        $this->assertStringContainsString(
            "Content-Security-Policy \"default-src 'none'; sandbox\"",
            $errorPolicy,
            'Caddy handler errors occur before deferred conditional headers are applied.',
        );
        $this->assertStringContainsString('respond "" {err.status_code}', $errorPolicy);
    }

    public function test_artifact_host_maintenance_mode_avoids_direct_cookie_bypass_responses(): void
    {
        foreach ([
            base_path('docker/Caddyfile') => '@artifactHostRuntime',
            base_path('docker/Caddyfile.security-errors') => '@artifactHostRuntimeError',
        ] as $configurationFile => $matcher) {
            $source = $this->source($configurationFile);

            $this->assertStringContainsString(
                $matcher . ' expression `{env.APP_RUNTIME_ROLE} == "artifact-host"`',
                $source,
            );
            $this->assertStringContainsString('header ' . $matcher . ' -Set-Cookie', $source);
        }

        $operations = $this->source(base_path('docs/OPERATIONS.md'));

        $this->assertStringContainsString(
            'use only plain `php artisan down` for an artifact-host HTTP role',
            $operations,
        );
        $this->assertStringContainsString('`--secret`, `--redirect`, and `--render`', $operations);
        $this->assertStringContainsString('`laravel_maintenance` cookie', $operations);
    }

    public function test_production_caddy_header_probe_runs_in_ci_and_nightly(): void
    {
        $probe = $this->source(base_path('scripts/verify-artifact-caddy-headers.sh'));

        $this->assertStringContainsString('assert_cookie_absent artifact-cookie-strip', $probe);
        $this->assertStringContainsString('assert_cookie_present app-cookie-preserved', $probe);
        $this->assertStringContainsString('assert_cookie_absent handler-error', $probe);

        foreach ([
            base_path('.github/workflows/ci.yml'),
            base_path('.github/workflows/nightly-audit.yml'),
        ] as $workflow) {
            $this->assertStringContainsString(
                'sh scripts/verify-artifact-caddy-headers.sh artifactflow-app:production',
                $this->source($workflow),
            );
        }
    }

    public function test_nightly_mcp_bridge_smoke_rejects_bearer_token_echo_on_both_output_streams(): void
    {
        $smoke = $this->source(base_path('scripts/smoke-mcp-remote.mjs'));

        $this->assertStringContainsString("spawn(\n  process.execPath,", $smoke);
        $this->assertStringContainsString('cwd: smokeWorkingDirectory', $smoke);
        $this->assertStringNotContainsString("'npx'", $smoke);
        $this->assertStringNotContainsString('spawnSync', $smoke);
        $this->assertStringContainsString('stderr.includes(BEARER_TOKEN)', $smoke);
        $this->assertStringContainsString('stdout.includes(BEARER_TOKEN)', $smoke);
    }

    /**
     * @return list<string>
     */
    private function controllerFiles(): array
    {
        $files = array_merge(
            glob(app_path('Http/Controllers/*.php')) ?: [],
            glob(app_path('Http/Controllers/*/*.php')) ?: [],
        );
        sort($files);

        return $files;
    }

    private function source(string $path): string
    {
        $source = file_get_contents($path);
        $this->assertIsString($source, sprintf('Unable to read %s.', $path));

        return $source;
    }
}
