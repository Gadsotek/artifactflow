<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\ChangeWorkspaceMembershipRole;
use App\Application\Identity\ChangeWorkspaceMembershipRoleCommand;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\RemoveWorkspaceMember;
use App\Application\Identity\RemoveWorkspaceMemberCommand;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pins the page->workspace lock ordering of the workspace-membership mutations.
 * Both handlers lock every page they will touch FOR UPDATE, ascending by uid,
 * BEFORE the workspace row -- matching the order a concurrent page save takes --
 * so Postgres cannot break a lock cycle by aborting one side (40P01 -> 500).
 */
final class WorkspaceMembershipLockOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_member_locks_every_touched_page_ascending_before_the_workspace(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin', 'lock-admin@example.test');
        $member = $this->createUser('Member', 'lock-member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Team');
        $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);

        // Pages inside the workspace plus one page elsewhere shared with it: the
        // removal touches (bumps preview revisions on) all of them, so all must be
        // locked before the workspace.
        $pageUids = [
            $this->page($admin, $workspace->uid, 'Alpha')->uid,
            $this->page($admin, $workspace->uid, 'Bravo')->uid,
            $this->page($admin, $workspace->uid, 'Charlie')->uid,
        ];

        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Other Team');
        $sharedPage = $this->page($admin, $otherWorkspace->uid, 'Shared');
        app(GrantPageAccess::class)->handle($admin, new GrantPageAccessCommand(
            pageUid: $sharedPage->uid,
            subjectType: PageAccessSubjectType::Workspace,
            subjectUid: $workspace->uid,
            role: WorkspaceRole::Reader,
        ));
        $pageUids[] = $sharedPage->uid;

        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $member->uid)
            ->sole();

        $lockLog = $this->recordLockOrder($workspace->uid, $pageUids, function () use ($admin, $workspace, $membership): void {
            app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $membership->uid,
                replacementOwnerUserUid: null,
            ));
        });

        $expected = $pageUids;
        sort($expected);

        $this->assertSame($expected, $this->pagesLockedBeforeWorkspace($lockLog));
    }

    public function test_changing_a_role_locks_every_workspace_page_ascending_before_the_workspace(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin', 'role-admin@example.test');
        $member = $this->createUser('Member', 'role-member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);

        $pageUids = [
            $this->page($admin, $workspace->uid, 'Alpha')->uid,
            $this->page($admin, $workspace->uid, 'Bravo')->uid,
            $this->page($admin, $workspace->uid, 'Charlie')->uid,
        ];

        $lockLog = $this->recordLockOrder($workspace->uid, $pageUids, function () use ($admin, $workspace, $membership): void {
            app(ChangeWorkspaceMembershipRole::class)->handle($admin, new ChangeWorkspaceMembershipRoleCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $membership->uid,
                role: WorkspaceRole::Editor,
            ));
        });

        $expected = $pageUids;
        sort($expected);

        $this->assertSame($expected, $this->pagesLockedBeforeWorkspace($lockLog));
    }

    public function test_removal_bumps_the_preview_revision_of_a_page_that_appears_after_the_presence_snapshot(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin', 'phantom-admin@example.test');
        $member = $this->createUser('Member', 'phantom-member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);

        $existing = $this->page($admin, $workspace->uid, 'Existing');
        $this->assertSame(0, (int) $existing->refresh()->preview_access_revision);

        // Inject a page into the workspace right AFTER the handler's presence snapshot
        // runs (DB::listen fires post-execution), so it is never in the snapshotted or
        // pre-locked set -- exactly the phantom a concurrent create produces, and the page
        // the transaction retry would pick up on replay. The workspace-scoped revision
        // bump is load-bearing security: the cookieless artifact preview authorizes solely
        // by the signature over preview_access_revision, so this bump is the only thing
        // that revokes a removed member's already-minted preview URLs. It must therefore
        // stay workspace-scoped and invalidate this page too; a "bump only the pre-locked
        // snapshot" optimisation would leave the removed member holding a valid URL to it.
        $phantomUid = null;

        DB::listen(function (QueryExecuted $query) use (&$phantomUid, $workspace, $admin): void {
            if ($phantomUid !== null) {
                return;
            }

            $sql = strtolower($query->sql);

            $isPresenceSnapshot = str_contains($sql, 'select')
                && str_contains($sql, '"pages"')
                && str_contains($sql, '"workspace_uid" = ?')
                && str_contains($sql, 'order by "uid"')
                && !str_contains($sql, 'for update')
                && in_array($workspace->uid, $query->bindings, true);

            if (!$isPresenceSnapshot) {
                return;
            }

            $phantom = Page::query()->forceCreate([
                'workspace_uid' => $workspace->uid,
                'owner_user_uid' => $admin->uid,
                'title' => 'Phantom',
                'slug' => 'phantom',
                'type' => PageType::Markdown,
                'status' => PageStatus::Draft,
            ]);
            $phantomUid = $phantom->uid;
        });

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: null,
        ));

        $this->assertNotNull($phantomUid, 'The phantom page must have been injected during the presence snapshot.');

        // The page that appeared after the snapshot is still invalidated by the removal.
        $phantom = Page::query()->findOrFail($phantomUid);
        $this->assertSame(1, (int) $phantom->preview_access_revision);
        $this->assertSame(1, (int) $existing->refresh()->preview_access_revision);
    }

    /**
     * Runs $action while recording an ordered log of FOR UPDATE locks on the given
     * workspace row and page rows (identified by their bound uids), ignoring locks
     * on unrelated rows and other tables.
     *
     * @param  list<string>  $pageUids
     * @return list<string>
     */
    private function recordLockOrder(string $workspaceUid, array $pageUids, callable $action): array
    {
        $log = [];

        DB::listen(function (QueryExecuted $query) use (&$log, $workspaceUid, $pageUids): void {
            $sql = strtolower($query->sql);

            if (!str_contains($sql, 'for update')) {
                return;
            }

            if (str_contains($sql, '"workspaces"')) {
                foreach ($query->bindings as $binding) {
                    if ($binding === $workspaceUid) {
                        $log[] = 'workspace';
                    }
                }

                return;
            }

            if (str_contains($sql, '"pages"')) {
                foreach ($query->bindings as $binding) {
                    if (in_array($binding, $pageUids, true)) {
                        $log[] = 'page:' . $binding;
                    }
                }
            }
        });

        $action();

        return $log;
    }

    /**
     * The page uids locked before the first workspace lock, in the order acquired.
     *
     * @param  list<string>  $log
     * @return list<string>
     */
    private function pagesLockedBeforeWorkspace(array $log): array
    {
        $workspaceIndex = array_search('workspace', $log, true);
        $this->assertNotFalse($workspaceIndex, 'The workspace row must be locked during the mutation.');

        $pages = [];

        foreach (array_slice($log, 0, $workspaceIndex) as $entry) {
            if (str_starts_with($entry, 'page:')) {
                $pages[] = substr($entry, 5);
            }
        }

        return $pages;
    }

    private function page(User $owner, string $workspaceUid, string $title): Page
    {
        return app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspaceUid,
            type: PageType::Markdown,
            title: $title,
            description: null,
            content: '# ' . $title,
        ));
    }

    private function createUser(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        app(\App\Application\Identity\CreatePersonalWorkspaceForUser::class)->handle($user);

        return $user;
    }

    private function addMember(string $workspaceUid, User $user, WorkspaceRole $role): WorkspaceMembership
    {
        return WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspaceUid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }
}
