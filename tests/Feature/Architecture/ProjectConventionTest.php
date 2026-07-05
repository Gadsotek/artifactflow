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

    public function test_page_access_revocation_journaling_is_shared_by_both_workflows(): void
    {
        foreach ([
            app_path('Application/Identity/RemoveWorkspaceMember.php'),
            app_path('Application/PageCatalog/RevokePageAccess.php'),
        ] as $handlerFile) {
            $source = $this->source($handlerFile);

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
