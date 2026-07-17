<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Events\PageContentVersionChanged;
use App\Events\PageEditingPresenceChanged;
use App\Models\InstallationSettings;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class PagePresenceBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_presence_channel_denies_a_non_viewer(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $outsider = $this->createUser('Outside User', 'outside@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Presence Page',
            description: null,
            content: '# Presence Page',
        ));

        $this->assertFalse($this->pageChannelCallback()($outsider, $page->uid));
    }

    public function test_presence_channel_allows_a_viewer_and_returns_minimal_identity(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Shared Presence Page',
            description: null,
            content: '# Shared Presence Page',
        ));

        $payload = $this->pageChannelCallback()($reader, $page->uid);

        $this->assertSame([
            'uid' => $reader->uid,
            'name' => 'Reader User',
        ], $payload);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('role', $payload);
        $this->assertArrayNotHasKey('content', $payload);
        $this->assertArrayNotHasKey('body', $payload);
    }

    public function test_presence_channel_denies_when_page_does_not_exist(): void
    {
        $user = $this->createUser('Missing Page User', 'missing-page@example.test');

        $this->assertFalse($this->pageChannelCallback()($user, '01J00000000000000000000000'));
    }

    public function test_presence_endpoint_broadcasts_server_authenticated_editor_identity(): void
    {
        Storage::fake('artifacts');
        Event::fake([PageEditingPresenceChanged::class]);

        $owner = $this->createUser('Owner User', 'presence-owner@example.test');
        $editor = $this->createUser('Editor User', 'presence-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Presence Endpoint Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $editor->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Presence Endpoint Page',
            description: null,
            content: '# Presence Endpoint Page',
        ));

        $this->actingAs($editor)
            ->postJson("/pages/{$page->uid}/presence", [
                'state' => 'editing',
                'uid' => $owner->uid,
                'name' => 'Forged Name',
            ])
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        Event::assertDispatched(PageEditingPresenceChanged::class, function (PageEditingPresenceChanged $event) use ($editor): bool {
            return $event->broadcastAs() === 'page.editing'
                && $event->broadcastWith() === [
                    'uid' => $editor->uid,
                    'name' => 'Editor User',
                    'editing' => true,
                ];
        });
    }

    public function test_presence_endpoint_rejects_non_editors_guests_and_invalid_state_without_broadcasting(): void
    {
        Storage::fake('artifacts');
        Event::fake([PageEditingPresenceChanged::class]);

        $owner = $this->createUser('Owner User', 'presence-deny-owner@example.test');
        $reader = $this->createUser('Reader User', 'presence-deny-reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Presence Deny Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Presence Deny Page',
            description: null,
            content: '# Presence Deny Page',
        ));

        $this->actingAs($reader)
            ->postJson("/pages/{$page->uid}/presence", ['state' => 'editing'])
            ->assertForbidden();

        auth()->logout();

        $this->postJson("/pages/{$page->uid}/presence", ['state' => 'editing'])
            ->assertUnauthorized();

        $this->actingAs($owner)
            ->postJson("/pages/{$page->uid}/presence", ['state' => 'typing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('state');

        Event::assertNotDispatched(PageEditingPresenceChanged::class);
    }

    public function test_page_detail_does_not_render_presence_mount_when_realtime_is_disabled(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner-off@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Realtime Off Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Realtime Off Page',
            description: null,
            content: '# Realtime Off Page',
        ));

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertDontSee('data-page-presence', false)
            ->assertDontSee('data-page-presence-editing-warning', false)
            ->assertDontSee('data-page-version-notice', false);
    }

    public function test_page_detail_renders_presence_mount_when_realtime_is_enabled(): void
    {
        Storage::fake('artifacts');

        $this->configureLocalReverb();
        $owner = $this->createUser('Owner User', 'owner-on@example.test');
        $this->createInstallationSettings(realtimeEnabled: true, updatedByUserUid: $owner->uid);
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Realtime On Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Realtime On Page',
            description: null,
            content: '# Realtime On Page',
        ));

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('data-realtime-enabled="true"', false)
            ->assertSee('data-page-presence', false)
            ->assertSee('data-page-presence-page-uid="' . $page->uid . '"', false)
            ->assertSee('data-page-presence-endpoint="/pages/' . $page->uid . '/presence"', false)
            ->assertSee('data-page-presence-current-user-uid="' . $owner->uid . '"', false)
            ->assertSee('data-page-presence-editing-warning', false)
            ->assertSee('data-page-presence-editing-warning-status', false)
            ->assertSee('data-page-version-notice', false)
            ->assertSee('data-current-version-uid="' . $page->current_version_uid . '"', false)
            ->assertSee('A newer version is available.', false)
            ->assertSee('View newer version', false)
            ->assertSee('aria-live="polite"', false);
    }

    public function test_content_update_does_not_broadcast_new_version_notice_when_realtime_is_disabled(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'version-notice-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Version Notice Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Version Notice Page',
            description: null,
            content: '# Initial',
        ));

        Event::fake([PageContentVersionChanged::class]);
        app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Realtime disabled',
            baseVersionUid: $page->current_version_uid,
        ));
        Event::assertNotDispatched(PageContentVersionChanged::class);
    }

    public function test_content_update_broadcasts_new_version_notice_when_realtime_is_enabled(): void
    {
        Storage::fake('artifacts');

        $this->configureLocalReverb();
        $owner = $this->createUser('Owner User', 'version-notice-enabled-owner@example.test');
        $this->createInstallationSettings(realtimeEnabled: true, updatedByUserUid: $owner->uid);
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Enabled Version Notice Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Enabled Version Notice Page',
            description: null,
            content: '# Initial',
        ));

        Event::fake([PageContentVersionChanged::class]);
        $version = app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Realtime enabled',
            baseVersionUid: $page->current_version_uid,
        ));

        Event::assertDispatched(
            PageContentVersionChanged::class,
            static fn (PageContentVersionChanged $event): bool => $event->broadcastAs() === 'page.version.created'
                && $event->broadcastWith() === [
                    'page_uid' => $page->uid,
                    'version_uid' => $version->uid,
                    'version_number' => $version->version_number,
                ],
        );
    }

    public function test_content_update_stays_successful_when_the_after_commit_broadcast_fails(): void
    {
        Storage::fake('artifacts');

        $this->configureLocalReverb();
        $owner = $this->createUser('Owner User', 'version-notice-failure-owner@example.test');
        $this->createInstallationSettings(realtimeEnabled: true, updatedByUserUid: $owner->uid);
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Failed Version Notice Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Failed Version Notice Page',
            description: null,
            content: '# Initial',
        ));

        /** @var Broadcaster&Mockery\MockInterface $failingBroadcaster */
        $failingBroadcaster = Mockery::mock(Broadcaster::class);
        /** @var Mockery\Expectation $broadcastExpectation */
        $broadcastExpectation = $failingBroadcaster->shouldReceive('broadcast');
        $broadcastExpectation->once();
        $broadcastExpectation->andThrow(new RuntimeException('Simulated Reverb outage.'));

        $broadcastManager = app(BroadcastFactory::class);
        $this->assertInstanceOf(BroadcastManager::class, $broadcastManager);
        $broadcastManager->purge('reverb');
        $broadcastManager->extend('reverb', fn (): Broadcaster => $failingBroadcaster);

        $version = app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Realtime delivery failed but save committed',
            baseVersionUid: $page->current_version_uid,
        ));

        $this->assertDatabaseHas('page_versions', [
            'uid' => $version->uid,
            'page_uid' => $page->uid,
        ]);
        $this->assertSame($version->uid, $page->refresh()->current_version_uid);
    }

    public function test_presence_client_asset_uses_the_presence_channel_without_mouse_tracking_or_private_fields(): void
    {
        $asset = $this->readProjectFile('resources/js/page-presence.js');
        $reverbConfig = $this->readProjectFile('config/reverb.php');

        $this->assertStringContainsString('Echo.join(`page.${pageUid}`)', $asset);
        $this->assertStringContainsString('sendPresenceState', $asset);
        $this->assertStringContainsString('const EDITING_TTL_MS = 90_000;', $asset);
        $this->assertStringContainsString("'focusin'", $asset);
        $this->assertStringContainsString("'keydown'", $asset);
        $this->assertStringContainsString("listen('.page.editing'", $asset);
        $this->assertStringContainsString("listen('.page.access.revoked'", $asset);
        $this->assertStringContainsString("listen('.page.version.created'", $asset);
        $this->assertStringContainsString('versionUid === currentVersionUid', $asset);
        $this->assertStringContainsString('versionNotice.hidden = false', $asset);
        $this->assertStringNotContainsString('window.location.reload', $asset);
        $this->assertStringContainsString('Echo.leave(`page.${pageUid}`)', $asset);
        $this->assertStringContainsString('editors.length === 2', $asset);
        $this->assertStringContainsString('`${editors[0]} and ${editors[1]} are editing`', $asset);
        $this->assertStringContainsString("querySelector('[data-page-presence-editing-warning]')", $asset);
        $this->assertStringContainsString('renderEditingWarning', $asset);
        $this->assertStringContainsString("leaving((member) =>", $asset);
        $this->assertStringNotContainsString('listenForWhisper', $asset);
        $this->assertStringNotContainsString('.whisper(', $asset);
        $this->assertStringNotContainsString('mousemove', $asset);
        $this->assertStringNotContainsString('email', $asset);
        $this->assertStringNotContainsString('role', $asset);
        $this->assertStringContainsString("'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'none')", $reverbConfig);
    }

    public function test_app_bootstrap_loads_presence_only_after_realtime_bootstrap(): void
    {
        $app = $this->readProjectFile('resources/js/app.js');

        $this->assertStringContainsString("import('./realtime').then", $app);
        $this->assertStringContainsString("import('./page-presence')", $app);
        $this->assertStringContainsString('[data-page-presence]', $app);
    }

    /**
     * @return callable(User, string): (array{uid: string, name: string}|false)
     */
    private function pageChannelCallback(): callable
    {
        $callback = Broadcast::getChannels()->get('page.{pageUid}');

        $this->assertIsCallable($callback, 'Expected routes/channels.php to register the page.{pageUid} channel.');

        return $callback;
    }

    private function configureLocalReverb(string $publicUrl = 'http://localhost:8080'): void
    {
        config([
            'app.reverb_url' => $publicUrl,
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.app_id' => 'artifactflow-local',
            'broadcasting.connections.reverb.key' => 'artifactflow-local-key',
            'broadcasting.connections.reverb.secret' => str_repeat('r', 32),
            'broadcasting.connections.reverb.options.host' => parse_url($publicUrl, PHP_URL_HOST),
            'broadcasting.connections.reverb.options.port' => parse_url($publicUrl, PHP_URL_PORT) ?: 8080,
            'broadcasting.connections.reverb.options.scheme' => parse_url($publicUrl, PHP_URL_SCHEME) ?: 'http',
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
