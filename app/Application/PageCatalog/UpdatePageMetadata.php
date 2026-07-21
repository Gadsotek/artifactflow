<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageMetadataException;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class UpdatePageMetadata
{
    public function __construct(
        private PageAccess $access,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageSearchVectorUpdater $searchVectors,
        private TagSynchronizer $tags,
        private SlugGenerator $slugs,
        private PageContentScanner $scanner,
        private RecordBlockedPageContentScan $recordBlockedScan,
        private PageAccessRevision $revisions,
        private PageOwnershipTransferRecorder $ownershipTransfers,
        private PageMetadataRules $metadataRules,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, UpdatePageMetadataCommand $command): Page
    {
        $actorUid = ActorId::fromUser($actor);

        return DB::transaction(function () use ($actor, $actorUid, $command): Page {
            $page = $this->lockPageAndNewParent(
                $command->pageUid,
                $this->normalizeOptionalUid($command->parentPageUid),
            );

            // Attaching a parent needs the whole workspace serialised, not just the two
            // page rows: two reparents on disjoint pairs (A under B while C under D) hold
            // no lock in common, and ensureParentIsValid() walks ancestors with plain
            // reads. Under READ COMMITTED neither sees the other's uncommitted edge, so
            // both validate a clean chain and commit A->B->C->D->A. Taken after the page
            // locks to match CreatePage's page-then-workspace order; inverting it here
            // would deadlock against a concurrent create. Detaching only removes an edge
            // and cannot close a cycle, so it skips the lock rather than serialising
            // every rename in the workspace.
            if ($this->normalizeOptionalUid($command->parentPageUid) !== null) {
                $this->lockWorkspace($page->workspace_uid);
            }

            // Re-authorize under the page row lock with fresh authority: the request may have
            // primed PageAccess's scoped cache before the lock, so an edit right revoked while
            // this request waited for the lock must still block the metadata write.
            $this->access->flushCache();

            if (!$this->access->canEdit($actor, $page)) {
                throw new AuthorizationException('You cannot edit this page.');
            }

            if ($page->metadata_revision !== $command->expectedMetadataRevision) {
                throw new StalePageMetadataException(
                    currentRevision: $page->metadata_revision,
                    submittedRevision: $command->expectedMetadataRevision,
                );
            }

            $title = $this->metadataRules->normalizeTitle($command->title);
            $description = $this->metadataRules->normalizeDescription($command->description);
            $this->ensureDescriptionIsSafe($actor, $page, $description);
            $categoryUid = $this->normalizeOptionalUid($command->categoryUid);
            $parentPageUid = $this->normalizeOptionalUid($command->parentPageUid);
            $ownerUserUid = $this->normalizeRequiredUid($command->ownerUserUid, 'Page owner is required.');
            $tagNames = $this->tags->uniqueNormalizedNames($command->tagNames);

            $this->metadataRules->ensureCategoryBelongsToWorkspace($categoryUid, $page->workspace_uid);
            $this->ensureParentIsValid($actor, $page, $parentPageUid);
            $this->metadataRules->ensureOwnerBelongsToWorkspace($ownerUserUid, $page->workspace_uid);

            $changedFields = $this->changedFields(
                page: $page,
                title: $title,
                description: $description,
                categoryUid: $categoryUid,
                parentPageUid: $parentPageUid,
                ownerUserUid: $ownerUserUid,
                tagNames: $tagNames,
            );

            if ($changedFields === []) {
                return $page->refresh();
            }

            if (in_array('owner_user_uid', $changedFields, true)) {
                $this->access->ensureCanTransferOwnership($actor, $page);
            }

            $attributes = [
                'title' => $title,
                'description' => $description,
                'category_uid' => $categoryUid,
                'parent_page_uid' => $parentPageUid,
                'owner_user_uid' => $ownerUserUid,
                'metadata_revision' => $page->metadata_revision + 1,
            ];

            if (in_array('title', $changedFields, true)) {
                $attributes['slug'] = $this->slugs->uniqueForWorkspace($page->workspace_uid, $title, $page->uid);
            }

            $previousOwnerUserUid = $page->owner_user_uid;
            $page->forceFill($attributes)->save();

            if (in_array('owner_user_uid', $changedFields, true)) {
                $this->revisions->bump($page);
            }

            if (in_array('tags', $changedFields, true)) {
                $this->tags->sync($page, $tagNames, $actorUid);
            }

            if (in_array('owner_user_uid', $changedFields, true)) {
                $this->ownershipTransfers->record(
                    page: $page,
                    previousOwnerUserUid: $previousOwnerUserUid,
                    newOwnerUserUid: $ownerUserUid,
                    actorUid: $actorUid,
                    reason: 'metadata_update',
                );
            }

            $this->searchVectors->refreshPage($page->uid);

            sort($changedFields);
            $changedFieldList = implode(',', $changedFields);
            $event = $this->events->record(
                eventType: DomainEventType::PageMetadataUpdated,
                aggregateType: 'page',
                aggregateUid: $page->uid,
                payload: [
                    'page_uid' => $page->uid,
                    'workspace_uid' => $page->workspace_uid,
                    'updated_by_user_uid' => $actorUid,
                    'changed_fields' => $changedFieldList,
                    'tag_count' => count($tagNames),
                ],
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'page',
                auditableUid: $page->uid,
                action: DomainEventType::PageMetadataUpdated,
                summary: 'Page metadata updated.',
                metadata: [
                    'workspace_uid' => $page->workspace_uid,
                    'changed_fields' => $changedFieldList,
                    'tag_count' => count($tagNames),
                ],
            );

            return $page->refresh();
        });
    }

    /**
     * Locks the page and, when a distinct parent is supplied, the new parent row too --
     * both FOR UPDATE and in ascending uid order. Locking self first and the new parent
     * second gives no catalog-wide lock order, so a mutual reparent (A under B while B
     * under A) inverts the order between the two transactions and deadlocks. A stable uid
     * order makes the loser wait instead; ensureParentIsValid() then runs under these
     * locks and rejects the now-visible cycle cleanly rather than 500ing on a deadlock.
     */
    private function lockPageAndNewParent(string $pageUid, ?string $newParentPageUid): Page
    {
        $uids = [$pageUid];

        if ($newParentPageUid !== null && $newParentPageUid !== $pageUid) {
            $uids[] = $newParentPageUid;
        }

        sort($uids);
        $page = null;

        foreach ($uids as $uid) {
            $locked = Page::query()->whereKey($uid)->lockForUpdate()->first();

            if ($locked instanceof Page && $locked->uid === $pageUid) {
                $page = $locked;
            }
        }

        if (!$page instanceof Page) {
            throw new DomainRuleViolation('Page does not exist.');
        }

        return $page;
    }

    private function lockWorkspace(string $workspaceUid): void
    {
        $locked = Workspace::query()->whereKey($workspaceUid)->lockForUpdate()->first();

        if (!$locked instanceof Workspace) {
            throw new DomainRuleViolation('Workspace does not exist.');
        }
    }

    private function ensureDescriptionIsSafe(User $actor, Page $page, ?string $description): void
    {
        if ($description === null) {
            return;
        }

        $scan = $this->scanner->scanDescription($description);

        if (!$scan->hasBlockedFindings()) {
            return;
        }

        $this->recordBlockedScan->forPageMetadata($actor, $page, $scan->blockedCodes());

        throw new BlockedPageContentException($scan->blockedCodes());
    }

    private function normalizeOptionalUid(?string $uid): ?string
    {
        if ($uid === null) {
            return null;
        }

        $normalizedUid = trim($uid);

        return $normalizedUid === '' ? null : $normalizedUid;
    }

    private function normalizeRequiredUid(string $uid, string $message): string
    {
        $normalizedUid = trim($uid);

        if ($normalizedUid === '') {
            throw new DomainRuleViolation($message);
        }

        return $normalizedUid;
    }

    private function ensureParentIsValid(User $actor, Page $page, ?string $parentPageUid): void
    {
        if ($parentPageUid === null) {
            return;
        }

        $parent = Page::query()
            ->where('uid', $parentPageUid)
            ->where('workspace_uid', $page->workspace_uid)
            ->first();

        if (!$parent instanceof Page) {
            throw new DomainRuleViolation('Parent page must belong to the selected workspace.');
        }

        if (!$this->access->canView($actor, $parent)) {
            throw new AuthorizationException('You cannot use this parent page.');
        }

        $visitedPageUids = [];

        while ($parent instanceof Page) {
            if ($parent->uid === $page->uid) {
                throw new DomainRuleViolation('A page cannot be its own parent or descendant.');
            }

            if (isset($visitedPageUids[$parent->uid])) {
                throw new DomainRuleViolation('Page hierarchy contains a cycle.');
            }

            $visitedPageUids[$parent->uid] = true;

            if ($parent->parent_page_uid === null) {
                return;
            }

            $parent = Page::query()->find($parent->parent_page_uid);
        }
    }

    /**
     * @param list<string> $tagNames
     *
     * @return list<string>
     */
    private function changedFields(
        Page $page,
        string $title,
        ?string $description,
        ?string $categoryUid,
        ?string $parentPageUid,
        string $ownerUserUid,
        array $tagNames,
    ): array {
        $changedFields = [];

        if ($page->title !== $title) {
            $changedFields[] = 'title';
        }

        if ($page->description !== $description) {
            $changedFields[] = 'description';
        }

        if ($page->category_uid !== $categoryUid) {
            $changedFields[] = 'category_uid';
        }

        if ($page->parent_page_uid !== $parentPageUid) {
            $changedFields[] = 'parent_page_uid';
        }

        if ($page->owner_user_uid !== $ownerUserUid) {
            $changedFields[] = 'owner_user_uid';
        }

        $currentTagNames = [];

        foreach ($page->tags()->pluck('name')->all() as $currentTagName) {
            if (is_string($currentTagName)) {
                $currentTagNames[] = mb_strtolower($currentTagName);
            }
        }

        sort($currentTagNames);
        $sortedTagNames = $tagNames;
        sort($sortedTagNames);

        if ($currentTagNames !== $sortedTagNames) {
            $changedFields[] = 'tags';
        }

        return $changedFields;
    }
}
