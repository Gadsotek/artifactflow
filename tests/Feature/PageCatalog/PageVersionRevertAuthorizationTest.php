<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\RevertToPreviousVersion;
use App\Application\PageCatalog\RevertToPreviousVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageVersionRevertAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_without_edit_access_cannot_revert_to_the_previous_version(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Editor User', 'revert-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Revert Guarded Runbook',
            description: null,
            content: '# Original',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $currentVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Revised',
            baseVersionUid: $firstVersion->uid,
        ));

        $outsider = $this->createUser('Outsider User', 'revert-outsider@example.test');

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('You cannot edit this page.');

        app(RevertToPreviousVersion::class)->handle($outsider, new RevertToPreviousVersionCommand(
            pageUid: $page->uid,
            baseVersionUid: $currentVersion->uid,
        ));
    }

    public function test_restore_rejects_a_stale_expected_current_version_with_a_conflict(): void
    {
        Storage::fake('artifacts');

        $editor = $this->createUser('Revert Editor', 'stale-revert-editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Stale Revert Runbook',
            description: null,
            content: '# V1',
        ));
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# V2',
            baseVersionUid: $firstVersion->uid,
        ));

        // The current version is now V2. Revert threads the version it observed as
        // current into the restore; when that is stale by the time the append lock is
        // held, the restore must be refused with a conflict rather than silently
        // overwriting the newer save -- even though the Restore source otherwise skips
        // the base-version check under the lock.
        try {
            app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
                expectedCurrentVersionUid: $firstVersion->uid,
            ));
            $this->fail('Expected a StalePageVersionException for a stale expected current version.');
        } catch (StalePageVersionException $exception) {
            $this->assertSame($secondVersion->uid, $exception->currentVersionUid);
        }

        $this->assertSame(2, PageVersion::query()->where('page_uid', $page->uid)->count());
        $this->assertSame($secondVersion->uid, $page->refresh()->current_version_uid);
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
