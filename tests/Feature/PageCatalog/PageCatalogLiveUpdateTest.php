<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Events\AccessiblePageCreated;
use App\Models\InstallationSettings;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageCatalogLiveUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_catalog_channel_allows_only_the_matching_authenticated_user(): void
    {
        $user = $this->createUser('Catalog User', 'catalog-channel@example.test');
        $other = $this->createUser('Other User', 'catalog-channel-other@example.test');
        $callback = Broadcast::getChannels()->get('user.{userUid}.page-catalog');

        $this->assertIsCallable($callback);
        $this->assertTrue($callback($user, $user->uid));
        $this->assertFalse($callback($other, $user->uid));
    }

    public function test_page_creation_does_not_publish_catalog_updates_when_realtime_is_disabled(): void
    {
        Storage::fake('artifacts');
        Event::fake([AccessiblePageCreated::class]);

        $owner = $this->createUser('Disabled Owner', 'catalog-disabled@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Disabled Catalog Team');

        app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'No Live Event',
            description: null,
            content: '# No Live Event',
        ));

        Event::assertNotDispatched(AccessiblePageCreated::class);
    }

    public function test_enabled_page_creation_publishes_minimal_invalidation_only_to_workspace_viewers(): void
    {
        Storage::fake('artifacts');
        $this->configureLocalReverb();

        $owner = $this->createUser('Live Owner', 'catalog-live-owner@example.test');
        $reader = $this->createUser('Live Reader', 'catalog-live-reader@example.test');
        $outsider = $this->createUser('Live Outsider', 'catalog-live-outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Live Catalog Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $this->createInstallationSettings(true, $owner->uid);
        Event::fake([AccessiblePageCreated::class]);

        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Live Catalog Page',
            description: 'Must not be broadcast.',
            content: '# Must not be broadcast',
        ));

        Event::assertDispatched(AccessiblePageCreated::class, function (AccessiblePageCreated $event) use (
            $owner,
            $page,
            $reader,
            $outsider,
            $workspace,
        ): bool {
            $channelNames = array_map(
                static fn (PrivateChannel $channel): string => $channel->name,
                $event->broadcastOn(),
            );

            $this->assertContains('private-user.' . $owner->uid . '.page-catalog', $channelNames);
            $this->assertContains('private-user.' . $reader->uid . '.page-catalog', $channelNames);
            $this->assertNotContains('private-user.' . $outsider->uid . '.page-catalog', $channelNames);
            $this->assertSame('page.created', $event->broadcastAs());
            $this->assertSame([
                'page_uid' => $page->uid,
                'workspace_uid' => $workspace->uid,
            ], $event->broadcastWith());

            return true;
        });
    }

    public function test_catalog_client_refetches_authorized_html_without_reloading_the_page(): void
    {
        $asset = $this->readProjectFile('resources/js/page-catalog-live.js');
        $app = $this->readProjectFile('resources/js/app.js');

        $this->assertStringContainsString('Echo.private(`user.${userUid}.page-catalog`)', $asset);
        $this->assertStringContainsString("listen('.page.created'", $asset);
        $this->assertStringContainsString('window.fetch(window.location.href', $asset);
        $this->assertStringContainsString("querySelector('[data-live-page-catalog]')", $asset);
        $this->assertStringNotContainsString('window.location.reload', $asset);
        $this->assertStringContainsString("import('./page-catalog-live')", $app);
    }

    private function configureLocalReverb(): void
    {
        config([
            'app.reverb_url' => 'http://localhost:8080',
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.app_id' => 'artifactflow-local',
            'broadcasting.connections.reverb.key' => 'artifactflow-local-key',
            'broadcasting.connections.reverb.secret' => str_repeat('r', 32),
        ]);
    }

    private function createInstallationSettings(bool $realtimeEnabled, string $updatedByUserUid): InstallationSettings
    {
        return InstallationSettings::query()->forceCreate([
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'max_markdown_bytes' => 4096,
            'max_html_bytes' => 4096,
            'artifact_max_bytes' => 4096,
            'max_workspace_storage_bytes' => 4096,
            'max_page_storage_bytes' => 4096,
            'max_page_versions' => 8,
            'max_tags_per_page' => 8,
            'two_factor_required_for_system_admins' => true,
            'two_factor_required_for_all_users' => false,
            'realtime_enabled' => $realtimeEnabled,
            'updated_by_user_uid' => $updatedByUserUid,
        ]);
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }
}
