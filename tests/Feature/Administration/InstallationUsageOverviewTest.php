<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Application\Administration\InstallationUsageOverview;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\ArtifactDerivativeKind;
use App\Domain\PageCatalog\PageType;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InstallationUsageOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_and_page_usage_include_artifact_derivatives(): void
    {
        Storage::fake('artifacts');
        config([
            'pages.max_page_storage_bytes' => 1_000,
            'pages.max_workspace_storage_bytes' => 2_000,
        ]);

        $admin = User::factory()->create(['is_system_admin' => true]);
        $editor = User::factory()->create();
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Office Usage Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $admin->uid,
            'role' => WorkspaceRole::Reader,
        ]);
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Office usage accounting',
            description: null,
            content: 'original',
        ));
        $version = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $derivativeBytes = 'derived-preview';

        PageVersionDerivative::query()->forceCreate([
            'uid' => (string) Str::ulid(),
            'page_version_uid' => $version->uid,
            'kind' => ArtifactDerivativeKind::XlsxManifest,
            'storage_path' => sprintf('pages/%s/versions/1-%s/manifest.json', $page->uid, $version->uid),
            'content_hash' => hash('sha256', $derivativeBytes),
            'byte_size' => strlen($derivativeBytes),
        ]);

        $usage = app(InstallationUsageOverview::class)->overview($admin);
        $expectedBytes = strlen('original') + strlen($derivativeBytes);

        $this->assertSame($expectedBytes, $usage->summary->usedBytes);
        $this->assertCount(1, $usage->workspaces);
        $this->assertSame($expectedBytes, $usage->workspaces[0]->usedBytes);
        $this->assertCount(1, $usage->pages);
        $this->assertSame($expectedBytes, $usage->pages[0]->usedBytes);
    }
}
