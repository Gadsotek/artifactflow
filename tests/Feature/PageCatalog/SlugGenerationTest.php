<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\MovePageToWorkspace;
use App\Application\PageCatalog\MovePageToWorkspaceCommand;
use App\Application\PageCatalog\SlugGenerator;
use App\Application\PageCatalog\UpdatePageMetadata;
use App\Application\PageCatalog\UpdatePageMetadataCommand;
use App\Domain\PageCatalog\PageType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SlugGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_maximum_length_titles_get_distinct_slugs_within_the_column_limit(): void
    {
        Storage::fake('artifacts');

        $author = $this->createUser('Author', 'author@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($author, 'Platform Team');

        // A maximum-length title: without a length cap the second page's slug
        // would be base(255) + "-2" = 257 chars and overflow the varchar(255)
        // pages.slug column.
        $title = str_repeat('a', 255);

        $first = app(CreatePage::class)->handle($author, $this->pageCommand($workspace->uid, $title));
        $second = app(CreatePage::class)->handle($author, $this->pageCommand($workspace->uid, $title));

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertLessThanOrEqual(SlugGenerator::MAX_LENGTH, mb_strlen($first->slug));
        $this->assertLessThanOrEqual(SlugGenerator::MAX_LENGTH, mb_strlen($second->slug));
        $this->assertSame(str_repeat('a', 255), $first->slug);
        $this->assertSame(str_repeat('a', 253) . '-2', $second->slug);
    }

    public function test_creating_many_colliding_pages_keeps_every_slug_within_the_column_limit(): void
    {
        Storage::fake('artifacts');

        $author = $this->createUser('Author', 'author@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($author, 'Platform Team');

        $title = str_repeat('a', 255);
        $slugs = [];

        for ($i = 0; $i < 12; $i++) {
            $page = app(CreatePage::class)->handle($author, $this->pageCommand($workspace->uid, $title));
            $this->assertLessThanOrEqual(SlugGenerator::MAX_LENGTH, mb_strlen($page->slug));
            $slugs[] = $page->slug;
        }

        // Every disambiguated slug is unique and fits the column.
        $this->assertCount(12, array_unique($slugs));
    }

    public function test_renaming_a_page_onto_an_existing_title_avoids_the_slug_collision(): void
    {
        Storage::fake('artifacts');

        $author = $this->createUser('Author', 'author@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($author, 'Platform Team');

        app(CreatePage::class)->handle($author, $this->pageCommand($workspace->uid, 'Runbook'));
        $second = app(CreatePage::class)->handle($author, $this->pageCommand($workspace->uid, 'Draft Notes'));

        $renamed = app(UpdatePageMetadata::class)->handle($author, new UpdatePageMetadataCommand(
            pageUid: $second->uid,
            title: 'Runbook',
            description: null,
            categoryUid: null,
            parentPageUid: null,
            ownerUserUid: $second->owner_user_uid,
            tagNames: [],
        ));

        $this->assertSame('runbook-2', $renamed->slug);
    }

    public function test_moving_a_page_into_a_workspace_with_a_conflicting_slug_disambiguates(): void
    {
        Storage::fake('artifacts');

        $admin = $this->createUser('Admin', 'admin@example.test');
        $source = app(CreateSharedWorkspace::class)->handle($admin, 'Source Team');
        $target = app(CreateSharedWorkspace::class)->handle($admin, 'Target Team');

        // The target already owns the 'runbook' slug.
        app(CreatePage::class)->handle($admin, $this->pageCommand($target->uid, 'Runbook'));
        $moving = app(CreatePage::class)->handle($admin, $this->pageCommand($source->uid, 'Runbook'));

        $moved = app(MovePageToWorkspace::class)->handle($admin, new MovePageToWorkspaceCommand(
            pageUid: $moving->uid,
            targetWorkspaceUid: $target->uid,
            targetOwnerUserUid: $admin->uid,
            confirmed: true,
        ));

        $this->assertSame($target->uid, $moved->workspace_uid);
        $this->assertSame('runbook-2', $moved->slug);
    }

    private function pageCommand(string $workspaceUid, string $title): CreatePageCommand
    {
        return new CreatePageCommand(
            workspaceUid: $workspaceUid,
            type: PageType::Markdown,
            title: $title,
            description: null,
            content: '# Heading' . PHP_EOL . PHP_EOL . 'Body text.',
        );
    }

    private function createUser(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('correct horse battery staple'),
        ]);

        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        return $user;
    }
}
