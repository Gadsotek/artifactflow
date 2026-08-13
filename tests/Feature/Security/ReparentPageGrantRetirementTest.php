<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Application\Identity\AcceptWorkspaceInvitation;
use App\Application\Identity\AddWorkspaceCollaborator;
use App\Application\Identity\AddWorkspaceCollaboratorCommand;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\InviteUserToWorkspace;
use App\Application\Identity\InviteUserToWorkspaceCommand;
use App\Application\Identity\ReparentWorkspace;
use App\Application\Identity\ReparentWorkspaceCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\HardDeletePage;
use App\Application\PageCatalog\HardDeletePageCommand;
use App\Application\PageCatalog\MovePageToWorkspace;
use App\Application\PageCatalog\MovePageToWorkspaceCommand;
use App\Application\PageCatalog\PageAccess;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembershipRemoval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ReparentPageGrantRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_reparent_membership_loss_retires_page_admin_before_reader_access_is_restored(): void
    {
        [$administrator, $formerMember, $child, $page, $grant] = $this->createReparentedPageWithHistoricalAdminGrant();

        $this->assertDatabaseMissing('page_access_grants', ['uid' => $grant->uid]);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'page.access_grant.revoked',
            'aggregate_uid' => $page->uid,
        ]);
        $revocationEvent = DomainEvent::query()
            ->where('event_type', 'page.access_grant.revoked')
            ->where('aggregate_uid', $page->uid)
            ->firstOrFail();
        $this->assertSame('workspace_hierarchy_membership_lost', $revocationEvent->payload['reason']);
        $this->assertDatabaseHas('audit_entries', [
            'event_uid' => $revocationEvent->uid,
            'action' => 'page.access_grant.revoked',
            'auditable_uid' => $grant->uid,
        ]);

        $hierarchyEvent = DomainEvent::query()
            ->where('event_type', 'workspace.hierarchy.changed')
            ->where('aggregate_uid', $child->uid)
            ->latest('uid')
            ->firstOrFail();
        $this->assertSame(1, $hierarchyEvent->payload['revoked_page_access_grant_count']);

        app(AddWorkspaceCollaborator::class)->handle(
            $administrator,
            new AddWorkspaceCollaboratorCommand($child->uid, $formerMember->uid, WorkspaceRole::Reader),
        );

        $access = app(PageAccess::class);
        $access->flushCache();
        $this->assertSame(WorkspaceRole::Reader, $access->workspaceRole($formerMember, $child->uid));
        $this->assertFalse($access->canView($formerMember, $page->refresh()));
        $this->assertFalse($access->canEdit($formerMember, $page));
        $this->assertFalse($access->canManageAccess($formerMember, $page));
        $this->assertFalse($access->canHardDelete($formerMember, $page));

        $attackerWorkspace = app(CreateSharedWorkspace::class)->handle($formerMember, 'Former member workspace');
        $accomplice = User::factory()->create();

        $this->assertAuthorizationDenied(static fn () => app(HardDeletePage::class)->handle(
            $formerMember,
            new HardDeletePageCommand($page->uid, $page->title),
        ));
        $this->assertAuthorizationDenied(static fn () => app(MovePageToWorkspace::class)->handle(
            $formerMember,
            new MovePageToWorkspaceCommand($page->uid, $attackerWorkspace->uid, $formerMember->uid, true),
        ));
        $this->assertAuthorizationDenied(static fn () => app(GrantPageAccess::class)->handle(
            $formerMember,
            new GrantPageAccessCommand(
                $page->uid,
                PageAccessSubjectType::User,
                $accomplice->uid,
                WorkspaceRole::Editor,
            ),
        ));

        $this->assertDatabaseHas('pages', [
            'uid' => $page->uid,
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $administrator->uid,
        ]);
        $this->assertDatabaseMissing('page_access_grants', [
            'page_uid' => $page->uid,
            'subject_uid' => $accomplice->uid,
        ]);
    }

    public function test_removal_journal_keeps_a_surviving_old_grant_dormant_but_allows_a_fresh_grant(): void
    {
        $administrator = User::factory()->create();
        $formerMember = User::factory()->create();
        $oldRoot = app(CreateSharedWorkspace::class)->handle($administrator, 'Journal old root');
        $newRoot = app(CreateSharedWorkspace::class)->handle($administrator, 'Journal new root');
        $child = app(CreateSharedWorkspace::class)->handle($administrator, 'Journal child');
        app(ReparentWorkspace::class)->handle(
            $administrator,
            new ReparentWorkspaceCommand($child->uid, $oldRoot->uid, true),
        );
        app(AddWorkspaceCollaborator::class)->handle(
            $administrator,
            new AddWorkspaceCollaboratorCommand($oldRoot->uid, $formerMember->uid, WorkspaceRole::Reader),
        );
        $page = Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $administrator->uid,
            'access_mode' => PageAccessMode::Restricted,
        ]);

        app(ReparentWorkspace::class)->handle(
            $administrator,
            new ReparentWorkspaceCommand($child->uid, $newRoot->uid, true),
        );
        $removal = WorkspaceMembershipRemoval::query()
            ->where('workspace_uid', $child->uid)
            ->where('user_uid', $formerMember->uid)
            ->firstOrFail();
        app(AddWorkspaceCollaborator::class)->handle(
            $administrator,
            new AddWorkspaceCollaboratorCommand($child->uid, $formerMember->uid, WorkspaceRole::Reader),
        );

        $staleGrant = PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::User,
            'subject_uid' => $formerMember->uid,
            'role' => WorkspaceRole::Admin,
            'granted_by_user_uid' => $administrator->uid,
            'created_at' => $removal->removed_at->subSecond(),
            'updated_at' => $removal->removed_at->subSecond(),
        ]);

        $access = app(PageAccess::class);
        $access->flushCache();
        $this->assertSame(WorkspaceRole::Reader, $access->workspaceRole($formerMember, $child->uid));
        $this->assertFalse($access->canHardDelete($formerMember, $page->refresh()));

        $staleGrant->delete();
        Date::setTestNow($removal->removed_at->addSecond());

        try {
            $freshGrant = app(GrantPageAccess::class)->handle(
                $administrator,
                new GrantPageAccessCommand(
                    $page->uid,
                    PageAccessSubjectType::User,
                    $formerMember->uid,
                    WorkspaceRole::Admin,
                ),
            );
        } finally {
            Date::setTestNow();
        }

        $access->flushCache();
        $this->assertTrue($freshGrant->created_at->greaterThan($removal->removed_at));
        $this->assertTrue($access->canHardDelete($formerMember, $page->refresh()));
    }

    public function test_reader_invitation_acceptance_does_not_reactivate_the_retired_admin_grant(): void
    {
        Mail::fake();

        [$administrator, $formerMember, $child, $page, $grant] = $this->createReparentedPageWithHistoricalAdminGrant();
        $invitation = app(InviteUserToWorkspace::class)->handle(
            $administrator,
            new InviteUserToWorkspaceCommand($child->uid, $formerMember->email, WorkspaceRole::Reader),
        );

        app(AcceptWorkspaceInvitation::class)->handle($formerMember, $invitation);

        $access = app(PageAccess::class);
        $access->flushCache();
        $this->assertDatabaseMissing('page_access_grants', ['uid' => $grant->uid]);
        $this->assertSame(WorkspaceRole::Reader, $access->workspaceRole($formerMember, $child->uid));
        $this->assertFalse($access->canView($formerMember, $page->refresh()));
        $this->assertFalse($access->canEdit($formerMember, $page));
        $this->assertFalse($access->canManageAccess($formerMember, $page));
        $this->assertFalse($access->canHardDelete($formerMember, $page));
    }

    public function test_later_inherited_reader_membership_does_not_reactivate_the_retired_admin_grant(): void
    {
        [$administrator, $formerMember, $child, $page, $grant] = $this->createReparentedPageWithHistoricalAdminGrant();
        $futureRoot = app(CreateSharedWorkspace::class)->handle($administrator, 'Future reader root');
        app(AddWorkspaceCollaborator::class)->handle(
            $administrator,
            new AddWorkspaceCollaboratorCommand($futureRoot->uid, $formerMember->uid, WorkspaceRole::Reader),
        );

        app(ReparentWorkspace::class)->handle(
            $administrator,
            new ReparentWorkspaceCommand($child->uid, $futureRoot->uid, true),
        );

        $access = app(PageAccess::class);
        $access->flushCache();
        $this->assertDatabaseMissing('page_access_grants', ['uid' => $grant->uid]);
        $this->assertSame(WorkspaceRole::Reader, $access->workspaceRole($formerMember, $child->uid));
        $this->assertFalse($access->canView($formerMember, $page->refresh()));
        $this->assertFalse($access->canEdit($formerMember, $page));
        $this->assertFalse($access->canManageAccess($formerMember, $page));
        $this->assertFalse($access->canHardDelete($formerMember, $page));
    }

    /**
     * @return array{User, User, Workspace, Page, PageAccessGrant}
     */
    private function createReparentedPageWithHistoricalAdminGrant(): array
    {
        $administrator = User::factory()->create();
        $formerMember = User::factory()->create();
        $oldRoot = app(CreateSharedWorkspace::class)->handle($administrator, 'Retirement old root');
        $newRoot = app(CreateSharedWorkspace::class)->handle($administrator, 'Retirement new root');
        $child = app(CreateSharedWorkspace::class)->handle($administrator, 'Retirement child');
        app(ReparentWorkspace::class)->handle(
            $administrator,
            new ReparentWorkspaceCommand($child->uid, $oldRoot->uid, true),
        );
        app(AddWorkspaceCollaborator::class)->handle(
            $administrator,
            new AddWorkspaceCollaboratorCommand($oldRoot->uid, $formerMember->uid, WorkspaceRole::Reader),
        );
        $page = Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $administrator->uid,
            'title' => 'Sensitive reparent target',
            'access_mode' => PageAccessMode::Restricted,
        ]);
        $grant = app(GrantPageAccess::class)->handle(
            $administrator,
            new GrantPageAccessCommand(
                $page->uid,
                PageAccessSubjectType::User,
                $formerMember->uid,
                WorkspaceRole::Admin,
            ),
        );

        app(ReparentWorkspace::class)->handle(
            $administrator,
            new ReparentWorkspaceCommand($child->uid, $newRoot->uid, true),
        );

        return [$administrator, $formerMember, $child, $page, $grant];
    }

    private function assertAuthorizationDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the stale page grant operation to be denied.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
