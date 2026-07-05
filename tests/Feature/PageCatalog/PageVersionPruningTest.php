<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The per-page version cap (pages.max_page_versions) is a retention limit, not a
 * hard wall: appending beyond the cap prunes the oldest surplus versions instead
 * of rejecting the edit. Content of a retained version stays immutable; only the
 * oldest whole versions are removed, their blobs deleted after commit, their
 * bytes released from the workspace counter, and each pruned version recorded as
 * its own durable domain event + audit entry.
 */
final class PageVersionPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_appending_beyond_the_cap_prunes_the_oldest_and_keeps_the_newest(): void
    {
        Storage::fake('artifacts');
        $this->configureLimits(maxVersions: 3);

        [$editor, $page] = $this->createPage('prune-keeps-newest@example.test', 'alpha');
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $firstBlobPath = $firstVersion->content_storage_path;

        $this->append($editor, $page, 'bravo');
        $third = $this->append($editor, $page, 'charlie');
        // Three versions now exist, exactly at the cap.
        $this->assertSame(3, $this->versionCount($page));

        $fourth = $this->append($editor, $page, 'delta');

        $this->assertSame(3, $this->versionCount($page));
        $this->assertSame([2, 3, 4], $this->survivingVersionNumbers($page));
        $this->assertDatabaseMissing('page_versions', ['uid' => $firstVersion->uid]);
        Storage::disk('artifacts')->assertMissing($firstBlobPath);
        Storage::disk('artifacts')->assertExists($third->content_storage_path);
        Storage::disk('artifacts')->assertExists($fourth->content_storage_path);
        $this->assertSame($fourth->uid, $this->currentVersionUid($page));

        // Version numbers keep climbing after a prune; they are never reused.
        $fifth = $this->append($editor, $page, 'echo');
        $this->assertSame(5, $fifth->version_number);
        $this->assertSame([3, 4, 5], $this->survivingVersionNumbers($page));
    }

    public function test_a_cap_of_one_keeps_only_the_new_current_version(): void
    {
        Storage::fake('artifacts');
        $this->configureLimits(maxVersions: 1);

        [$editor, $page] = $this->createPage('prune-cap-one@example.test', 'one');
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $second = $this->append($editor, $page, 'two');

        $this->assertSame(1, $this->versionCount($page));
        $sole = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $this->assertSame($second->uid, $sole->uid);
        $this->assertSame($second->uid, $this->currentVersionUid($page));
        Storage::disk('artifacts')->assertMissing($firstVersion->content_storage_path);
    }

    public function test_lowering_the_cap_prunes_multiple_versions_with_one_event_each(): void
    {
        Storage::fake('artifacts');
        $this->configureLimits(maxVersions: 5);

        [$editor, $page] = $this->createPage('prune-lowered-cap@example.test', 'one');
        $this->append($editor, $page, 'two');
        $this->append($editor, $page, 'three');
        $this->append($editor, $page, 'four');
        $this->assertSame(4, $this->versionCount($page));

        $prunable = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->orderBy('version_number')
            ->limit(3)
            ->pluck('uid')
            ->all();

        $this->configureLimits(maxVersions: 2);
        $this->append($editor, $page, 'five');

        $this->assertSame(2, $this->versionCount($page));
        $this->assertSame([4, 5], $this->survivingVersionNumbers($page));

        $prunedEvents = DomainEvent::query()
            ->where('event_type', 'page.version.pruned')
            ->get();
        $this->assertCount(3, $prunedEvents);
        $prunedVersionUids = $prunedEvents
            ->map(fn (DomainEvent $event): mixed => $event->payload['page_version_uid'])
            ->all();
        sort($prunedVersionUids);
        sort($prunable);
        $this->assertSame($prunable, $prunedVersionUids);
    }

    public function test_pruning_releases_the_workspace_storage_counter(): void
    {
        Storage::fake('artifacts');
        $this->configureLimits(maxVersions: 2);

        [$editor, $page] = $this->createPage('prune-storage-counter@example.test', 'a');
        $this->append($editor, $page, 'bb');
        $this->append($editor, $page, 'ccc');

        $survivingBytes = (int) PageVersion::query()
            ->where('page_uid', $page->uid)
            ->sum('byte_size');
        $workspace = Workspace::query()->whereKey($page->workspace_uid)->sole();

        $this->assertSame(2, $this->versionCount($page));
        $this->assertSame($survivingBytes, $workspace->used_storage_bytes);
    }

    public function test_each_pruned_version_is_recorded_without_leaking_content(): void
    {
        Storage::fake('artifacts');
        $this->configureLimits(maxVersions: 2);

        [$editor, $page] = $this->createPage('prune-audit-trail@example.test', 'one');
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $this->append($editor, $page, 'two');
        $this->append($editor, $page, 'three');

        $event = DomainEvent::query()
            ->where('event_type', 'page.version.pruned')
            ->get()
            ->firstWhere(fn (DomainEvent $candidate): bool => $candidate->payload['page_version_uid'] === $firstVersion->uid);

        $this->assertNotNull($event);
        $this->assertSame($page->uid, $event->payload['page_uid']);
        $this->assertSame(1, $event->payload['version_number']);
        $this->assertSame($firstVersion->byte_size, $event->payload['byte_size']);
        $this->assertSame($editor->uid, $event->payload['pruned_by_user_uid']);
        $this->assertArrayNotHasKey('content', $event->payload);
        $this->assertArrayNotHasKey('content_hash', $event->payload);
        $this->assertArrayNotHasKey('source_text', $event->payload);
        $this->assertArrayNotHasKey('extracted_text', $event->payload);

        $this->assertDatabaseHas('audit_entries', [
            'action' => 'page.version.pruned',
            'auditable_uid' => $firstVersion->uid,
        ]);
    }

    public function test_restoring_a_version_also_prunes_to_the_cap(): void
    {
        Storage::fake('artifacts');
        $this->configureLimits(maxVersions: 2);

        [$editor, $page] = $this->createPage('prune-on-restore@example.test', 'one');
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $this->append($editor, $page, 'two');

        // Restoring v1 appends a third version (its content), tipping the page
        // past the cap of 2 and pruning the oldest surviving version.
        $restored = app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
            pageUid: $page->uid,
            versionUid: $firstVersion->uid,
        ));

        $this->assertSame(2, $this->versionCount($page));
        $this->assertSame([2, 3], $this->survivingVersionNumbers($page));
        $this->assertSame($restored->uid, $this->currentVersionUid($page));
        $this->assertDatabaseMissing('page_versions', ['uid' => $firstVersion->uid]);
        Storage::disk('artifacts')->assertMissing($firstVersion->content_storage_path);
    }

    public function test_pruning_applies_to_mcp_sourced_updates(): void
    {
        Storage::fake('artifacts');
        $this->configureLimits(maxVersions: 1);

        [$editor, $page] = $this->createPage('prune-mcp-source@example.test', 'one');
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();

        $second = $this->append($editor, $page, 'two', PageVersionSource::Mcp);

        $this->assertSame(1, $this->versionCount($page));
        $this->assertSame($second->uid, PageVersion::query()->where('page_uid', $page->uid)->sole()->uid);
        Storage::disk('artifacts')->assertMissing($firstVersion->content_storage_path);
    }

    public function test_append_at_the_version_cap_is_not_wedged_by_bytes_the_same_prune_reclaims(): void
    {
        // A page sitting at the version-retention cap must not be blocked by the
        // per-page byte quota over bytes the SAME append is about to prune off the
        // end. Only versions that survive the append+prune may count against the
        // quota; otherwise a busy page wedges permanently once it nears the byte
        // cap, even though every append reclaims an older version's bytes.
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 10_000,
            'pages.max_page_versions' => 2,
            'pages.max_workspace_storage_bytes' => 1_000_000,
            'pages.max_page_storage_bytes' => 1_000_000,
        ]);

        [$editor, $page] = $this->createPage('quota-prune-wedge@example.test', 'a');
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $secondVersion = $this->append($editor, $page, 'bbbb');

        // Exactly at the retention cap of two versions.
        $this->assertSame(2, $this->versionCount($page));
        $this->assertGreaterThan(0, $firstVersion->byte_size);

        // Tighten the byte cap so it admits only the versions that survive the next
        // append+prune: the newest existing version plus the newcomer (identical
        // content -> identical bytes). The oldest version's bytes -- which the same
        // append prunes away -- push the naive all-versions sum over this cap, so a
        // prune-blind quota would wrongly reject the append.
        config(['pages.max_page_storage_bytes' => $secondVersion->byte_size * 2]);

        $third = $this->append($editor, $page, 'bbbb');

        $this->assertSame(2, $this->versionCount($page));
        $this->assertSame([2, 3], $this->survivingVersionNumbers($page));
        $this->assertDatabaseMissing('page_versions', ['uid' => $firstVersion->uid]);
        $this->assertSame($third->uid, $this->currentVersionUid($page));
    }

    public function test_append_at_the_version_cap_is_still_rejected_when_survivors_alone_exceed_the_quota(): void
    {
        // Prune-awareness must not become a bypass of a genuine overflow: when even
        // the versions that WOULD survive the append+prune (newest existing version
        // + newcomer) exceed the byte cap, the append must still be rejected.
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 10_000,
            'pages.max_page_versions' => 2,
            'pages.max_workspace_storage_bytes' => 1_000_000,
            'pages.max_page_storage_bytes' => 1_000_000,
        ]);

        [$editor, $page] = $this->createPage('quota-prune-still-full@example.test', 'a');
        $secondVersion = $this->append($editor, $page, 'bbbb');
        $this->assertSame(2, $this->versionCount($page));

        // One byte below the survivors of the next append+prune, so the page would
        // still overflow even after the oldest version is pruned.
        config(['pages.max_page_storage_bytes' => $secondVersion->byte_size * 2 - 1]);

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Page storage quota exceeded.');
        $this->append($editor, $page, 'bbbb');
    }

    public function test_append_at_the_version_cap_is_not_wedged_by_workspace_bytes_the_same_prune_reclaims(): void
    {
        // Workspace analogue of the per-page prune-aware quota: an append at the
        // version cap must not be blocked by the WORKSPACE quota over bytes the
        // same transaction reclaims by pruning this page's oldest version.
        // Versions are 1 and 4 bytes (workspace usage 5), the newcomer is 4 bytes,
        // and the workspace cap is 8 -- the append commits at exactly 8 because the
        // 1-byte version is pruned in the same transaction.
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 10_000,
            'pages.max_page_versions' => 2,
            'pages.max_page_storage_bytes' => 1_000_000,
            'pages.max_workspace_storage_bytes' => 8,
        ]);

        [$editor, $page] = $this->createPage('workspace-quota-prune-wedge@example.test', 'a');
        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $this->append($editor, $page, 'bbbb');

        // Two versions (1 + 4 bytes) exactly at the retention cap; workspace usage 5.
        $this->assertSame(2, $this->versionCount($page));
        $this->assertSame(5, $this->workspaceUsage($page));

        $third = $this->append($editor, $page, 'bbbb');

        $this->assertSame(2, $this->versionCount($page));
        $this->assertSame([2, 3], $this->survivingVersionNumbers($page));
        $this->assertDatabaseMissing('page_versions', ['uid' => $firstVersion->uid]);
        $this->assertSame($third->uid, $this->currentVersionUid($page));
        $this->assertSame(8, $this->workspaceUsage($page));
    }

    public function test_append_at_the_version_cap_is_still_rejected_when_workspace_survivors_exceed_the_quota(): void
    {
        // One byte tighter (limit 7): even after pruning the 1-byte version the
        // workspace would settle at 8, so the append must still be rejected. The
        // projection discounts prunable bytes; it does not bypass a real overflow.
        Storage::fake('artifacts');
        config([
            'pages.max_markdown_bytes' => 10_000,
            'pages.max_page_versions' => 2,
            'pages.max_page_storage_bytes' => 1_000_000,
            'pages.max_workspace_storage_bytes' => 7,
        ]);

        [$editor, $page] = $this->createPage('workspace-quota-still-full@example.test', 'a');
        $this->append($editor, $page, 'bbbb');
        $this->assertSame(2, $this->versionCount($page));
        $this->assertSame(5, $this->workspaceUsage($page));

        try {
            $this->append($editor, $page, 'bbbb');
            $this->fail('Expected the workspace quota to reject the append.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Workspace page storage quota exceeded.', $exception->getMessage());
        }

        // The rejected append rolled back cleanly: page and counter are untouched.
        $this->assertSame(2, $this->versionCount($page));
        $this->assertSame(5, $this->workspaceUsage($page));
    }

    private function configureLimits(int $maxVersions): void
    {
        config([
            'pages.max_markdown_bytes' => 10_000,
            'pages.max_page_versions' => $maxVersions,
            'pages.max_page_storage_bytes' => 1_000_000,
            'pages.max_workspace_storage_bytes' => 1_000_000,
        ]);
    }

    /**
     * @return array{0: User, 1: Page}
     */
    private function createPage(string $email, string $content): array
    {
        $editor = app(CreateUser::class)->handle('Editor User', $email, 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($editor, 'Platform Team');
        $page = app(CreatePage::class)->handle($editor, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Retention Page',
            description: null,
            content: $content,
        ));

        return [$editor, $page];
    }

    private function append(
        User $editor,
        Page $page,
        string $content,
        PageVersionSource $source = PageVersionSource::Editor,
    ): PageVersion {
        return app(UpdatePageContent::class)->handle($editor, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: $content,
            source: $source,
            baseVersionUid: $this->currentVersionUid($page),
        ));
    }

    private function currentVersionUid(Page $page): string
    {
        return (string) Page::query()->whereKey($page->uid)->sole()->current_version_uid;
    }

    private function workspaceUsage(Page $page): int
    {
        return (int) Workspace::query()->whereKey($page->workspace_uid)->sole()->used_storage_bytes;
    }

    private function versionCount(Page $page): int
    {
        return PageVersion::query()->where('page_uid', $page->uid)->count();
    }

    /**
     * @return list<int>
     */
    private function survivingVersionNumbers(Page $page): array
    {
        $numbers = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->orderBy('version_number')
            ->pluck('version_number')
            ->all();

        $result = [];

        foreach ($numbers as $number) {
            $result[] = is_numeric($number) ? (int) $number : 0;
        }

        return $result;
    }
}
