<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ChannelAuthorizationConventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_callbacks_deny_when_the_user_cannot_access_the_resource(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $outsider = $this->createUser('Outside User', 'outside@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Realtime Page',
            description: null,
            content: '# Realtime Page',
        ));

        $callback = $this->pageChannelCallback();

        $this->assertFalse($callback($outsider, $page->uid));
    }

    public function test_presence_channel_payloads_contain_only_minimal_identity(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Shared Realtime Page',
            description: null,
            content: '# Shared Realtime Page',
        ));
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $reader->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        $callback = $this->pageChannelCallback();
        $payload = $callback($reader, $page->uid);

        $this->assertSame([
            'uid' => $reader->uid,
            'name' => 'Reader User',
        ], $payload);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('role', $payload);
        $this->assertArrayNotHasKey('content', $payload);
        $this->assertArrayNotHasKey('body', $payload);
        $this->assertArrayNotHasKey('html', $payload);
        $this->assertArrayNotHasKey('markdown', $payload);
    }

    public function test_page_channel_denies_when_the_page_does_not_exist(): void
    {
        $user = $this->createUser('Missing Page User', 'missing-page@example.test');
        $callback = $this->pageChannelCallback();

        $this->assertFalse($callback($user, '01J00000000000000000000000'));
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

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }
}
