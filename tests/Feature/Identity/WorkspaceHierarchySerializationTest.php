<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\MovePageToWorkspace;
use App\Application\PageCatalog\MovePageToWorkspaceCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WorkspaceHierarchySerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_creation_joins_hierarchy_serialization_before_placing_the_page(): void
    {
        Storage::fake('artifacts');
        $admin = $this->createUser();
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Create Serialization');
        $events = $this->recordHierarchyAndPageWrites(function () use ($admin, $workspace): void {
            app(CreatePage::class)->handle($admin, new CreatePageCommand(
                workspaceUid: $workspace->uid,
                type: PageType::Markdown,
                title: 'Serialized create',
                description: null,
                content: '# Serialized create',
            ));
        });

        $this->assertHierarchyPrecedes($events, 'page-write');
    }

    public function test_page_move_joins_hierarchy_serialization_before_locking_the_page(): void
    {
        Storage::fake('artifacts');
        $admin = $this->createUser();
        $source = app(CreateSharedWorkspace::class)->handle($admin, 'Move Source');
        $target = app(CreateSharedWorkspace::class)->handle($admin, 'Move Target');
        $page = $this->page($admin, $source->uid, 'Move target page');
        $events = $this->recordHierarchyAndPageWrites(function () use ($admin, $page, $target): void {
            app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
                pageUid: $page->uid,
                targetWorkspaceUid: $target->uid,
                targetOwnerUserUid: $admin->uid,
                confirmed: true,
            ));
        });

        $this->assertHierarchyPrecedes($events, 'page-lock');
    }

    public function test_workspace_grant_joins_hierarchy_serialization_before_locking_the_page(): void
    {
        Storage::fake('artifacts');
        $admin = $this->createUser();
        $pageWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Grant Page Workspace');
        $subjectWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Grant Subject Workspace');
        $page = $this->page($admin, $pageWorkspace->uid, 'Grant target page');
        $events = $this->recordHierarchyAndPageWrites(function () use ($admin, $page, $subjectWorkspace): void {
            app(GrantPageAccess::class)->handle($admin, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::Workspace,
                subjectUid: $subjectWorkspace->uid,
                role: WorkspaceRole::Reader,
            ));
        });

        $this->assertHierarchyPrecedes($events, 'page-lock');
    }

    /**
     * @return list<string>
     */
    private function recordHierarchyAndPageWrites(callable $action): array
    {
        $events = [];

        DB::listen(static function (QueryExecuted $query) use (&$events): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'pg_advisory_xact_lock')) {
                $events[] = 'hierarchy-lock';
            }

            if (str_contains($sql, 'for update') && str_contains($sql, '"pages"')) {
                $events[] = 'page-lock';
            }

            if (str_starts_with(ltrim($sql), 'insert into "pages"')) {
                $events[] = 'page-write';
            }
        });

        $action();

        return $events;
    }

    /**
     * @param list<string> $events
     */
    private function assertHierarchyPrecedes(array $events, string $pageEvent): void
    {
        $hierarchyIndex = array_search('hierarchy-lock', $events, true);
        $pageIndex = array_search($pageEvent, $events, true);

        $this->assertNotFalse($hierarchyIndex, 'The hierarchy advisory lock must be acquired.');
        $this->assertNotFalse($pageIndex, 'The page mutation must be observed.');
        $this->assertLessThan($pageIndex, $hierarchyIndex);
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

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Serialization Admin',
            'email' => 'serialization-admin@example.test',
            'password' => Hash::make('password'),
        ]);
    }
}
