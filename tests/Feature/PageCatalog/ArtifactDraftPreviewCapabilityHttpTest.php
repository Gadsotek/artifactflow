<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\Identity\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ArtifactDraftPreviewCapabilityHttpTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = '/pages/draft-preview-capabilities';

    public function test_an_authenticated_workspace_editor_can_issue_a_content_bound_capability(): void
    {
        [$editor, $workspaceUid] = $this->editorInWorkspace();
        $content = '<p>authorized draft</p>';

        $this->actingAs($editor)
            ->postJson(self::ENDPOINT, $this->payload($workspaceUid, $content))
            ->assertOk()
            ->assertJsonStructure(['capability', 'expires_at'])
            ->assertJsonPath('expires_at', fn (mixed $value): bool => is_int($value));
    }

    public function test_capability_issuance_requires_authentication(): void
    {
        $owner = $this->user('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');

        $this->postJson(self::ENDPOINT, $this->payload($workspace->uid, '<p>draft</p>'))
            ->assertUnauthorized();
    }

    public function test_a_workspace_reader_cannot_issue_a_capability(): void
    {
        $owner = $this->user('Owner', 'owner@example.test');
        $reader = $this->user('Reader', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);

        $this->actingAs($reader)
            ->postJson(self::ENDPOINT, $this->payload($workspace->uid, '<p>draft</p>'))
            ->assertForbidden();
    }

    public function test_an_editor_cannot_issue_a_capability_for_another_workspace(): void
    {
        [$editor] = $this->editorInWorkspace();
        $foreignOwner = $this->user('Foreign Owner', 'foreign@example.test');
        $foreignWorkspace = app(CreateSharedWorkspace::class)->handle($foreignOwner, 'Foreign Team');

        $this->actingAs($editor)
            ->postJson(self::ENDPOINT, $this->payload($foreignWorkspace->uid, '<p>draft</p>'))
            ->assertForbidden();
    }

    public function test_capability_claims_are_strictly_validated(): void
    {
        [$editor, $workspaceUid] = $this->editorInWorkspace();

        $this->actingAs($editor)
            ->postJson(self::ENDPOINT, [
                'workspace_uid' => $workspaceUid,
                'content_bytes' => 0,
                'content_sha256' => 'NOT-A-SHA-256',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content_bytes', 'content_sha256']);

        config(['pages.max_html_bytes' => 64]);

        $this->actingAs($editor)
            ->postJson(self::ENDPOINT, [
                'workspace_uid' => $workspaceUid,
                'content_bytes' => 65,
                'content_sha256' => hash('sha256', 'content'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content_bytes']);
    }

    public function test_capability_issuance_is_absent_from_the_artifact_host_runtime(): void
    {
        [$editor, $workspaceUid] = $this->editorInWorkspace();
        config(['app.runtime_role' => 'artifact-host']);

        $this->actingAs($editor)
            ->postJson(self::ENDPOINT, $this->payload($workspaceUid, '<p>draft</p>'))
            ->assertNotFound();
    }

    public function test_capability_issuance_is_rate_limited_per_authenticated_user(): void
    {
        config(['rate_limits.draft_preview_capabilities_per_minute' => 1]);
        [$editor, $workspaceUid] = $this->editorInWorkspace();

        $this->actingAs($editor)
            ->postJson(self::ENDPOINT, $this->payload($workspaceUid, '<p>first</p>'))
            ->assertOk();

        $this->actingAs($editor)
            ->postJson(self::ENDPOINT, $this->payload($workspaceUid, '<p>second</p>'))
            ->assertTooManyRequests();
    }

    /**
     * @return array{User, string}
     */
    private function editorInWorkspace(): array
    {
        $owner = $this->user('Owner', fake()->unique()->safeEmail());
        $editor = $this->user('Editor', fake()->unique()->safeEmail());
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);

        return [$editor, $workspace->uid];
    }

    private function addMember(string $workspaceUid, User $user, WorkspaceRole $role): void
    {
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspaceUid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }

    private function user(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    /**
     * @return array{workspace_uid: string, content_bytes: int, content_sha256: string}
     */
    private function payload(string $workspaceUid, string $content): array
    {
        return [
            'workspace_uid' => $workspaceUid,
            'content_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
        ];
    }
}
