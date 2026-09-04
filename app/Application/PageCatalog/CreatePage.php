<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\RealtimeConfiguration;
use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Application\Identity\WorkspaceHierarchyGraph;
use App\Application\Mcp\McpRequestContext;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\CategoryRuleViolation;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Events\AccessiblePageCreated;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class CreatePage
{
    public function __construct(
        private PageAccess $access,
        private PageContentScanner $scanner,
        private PageContentPreparer $contentPreparer,
        private RecordBlockedPageContentScan $recordBlockedScan,
        private PageSecurityWarningRecorder $securityWarnings,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private PageSearchVectorUpdater $searchVectors,
        private WorkspaceStorageQuota $storageQuota,
        private PageVersionWriter $versionWriter,
        private TagSynchronizer $tags,
        private SlugGenerator $slugs,
        private McpRequestContext $mcpContext,
        private PageMetadataRules $metadataRules,
        private PageVersionChangeSummaryRules $changeSummaryRules,
        private CreateCategory $createCategory,
        private RealtimeConfiguration $realtimeConfiguration,
        private PageCatalogLiveAudience $liveAudience,
        private WorkspaceHierarchyGraph $workspaceHierarchy,
        private PageVersionStorage $versionStorage,
        private OfficeArtifactStoragePreflight $officeStoragePreflight,
    ) {
    }

    public function handle(User $actor, CreatePageCommand $command): Page
    {
        $actorUid = ActorId::fromUser($actor);
        $this->access->ensureCanCreateInWorkspace($actor, $command->workspaceUid);

        $workspace = $this->workspace($command->workspaceUid);
        $title = $this->metadataRules->normalizeTitle($command->title);
        $description = $this->metadataRules->normalizeDescription($command->description);
        $this->ensureDescriptionIsSafe($actor, $workspace, $command->type, $description);
        try {
            $changeSummary = $this->changeSummaryRules->normalize($command->changeSummary);
        } catch (BlockedPageContentException $exception) {
            $this->recordBlockedScan->forPageCreation(
                actor: $actor,
                workspace: $workspace,
                pageType: $command->type,
                findingCodes: $exception->findingCodes(),
                operation: 'create_page_change_summary',
            );

            throw $exception;
        }
        $this->contentPreparer->validateInput($command->type, $command->content);
        $ownerUserUid = $this->resolveOwnerUserUid($actorUid, $command);
        $this->metadataRules->ensureOwnerBelongsToWorkspace($ownerUserUid, $workspace->uid);
        $this->ensureStatusCanBeUsedForNewPage($command->status);
        $this->contentPreparer->validateSourceFilename($command->type, $command->sourceFilename);
        $this->ensureCategoryInputIsUnambiguous($command);
        $this->metadataRules->ensureCategoryBelongsToWorkspace($command->categoryUid, $workspace->uid);
        $this->ensureParentPageIsAvailable($actor, $command->parentPageUid, $workspace->uid);
        $this->officeStoragePreflight->forNewPage($workspace, $command->type, $command->content);

        try {
            $prepared = $this->contentPreparer->prepareForPersistence(
                $command->type,
                $command->content,
                $actorUid,
                $command->source,
            );
        } catch (BlockedPageContentException $exception) {
            $this->recordBlockedScan->forPageCreation(
                actor: $actor,
                workspace: $workspace,
                pageType: $command->type,
                findingCodes: $exception->findingCodes(),
            );

            throw $exception;
        }

        $byteSize = $prepared->byteSize();
        $scan = $prepared->scan;
        $storagePaths = [];
        $transactionCallbackCompleted = false;

        try {
            $page = DB::transaction(function () use (
                $actorUid,
                $actor,
                $command,
                $byteSize,
                $description,
                $ownerUserUid,
                $prepared,
                $scan,
                &$storagePaths,
                &$transactionCallbackCompleted,
                $title,
                $workspace,
                $changeSummary,
            ): Page {
                // Page placement participates in the same hierarchy transaction
                // protocol as reparenting. Acquiring this before any page or
                // workspace row prevents a post-snapshot page from escaping the
                // reparent preview-revision and owner-eligibility checks.
                $this->workspaceHierarchy->acquireMutationLock();
                $lockedParent = null;

                if ($command->parentPageUid !== null) {
                    // Lock the parent page before the workspace so page creation
                    // follows the catalog-wide page -> workspace lock order.
                    // Inserting the child takes an implicit foreign-key KEY SHARE
                    // lock on this parent row; taking FOR UPDATE up front -- the
                    // same lock a rename or version append on the parent holds --
                    // makes those operations serialize on the page row instead of
                    // deadlocking against the workspace lock acquired next.
                    $lockedParent = Page::query()->whereKey($command->parentPageUid)->lockForUpdate()->first();
                }

                $lockedWorkspace = $this->storageQuota->lockWorkspaceForStorageUpdate($workspace->uid);

                // Workspace membership changes serialize on this lock. Refresh the actor's
                // request-scoped authority after taking it so a demotion/removal that committed
                // while creation waited prevents the write even when the owner is somebody else.
                $this->access->flushCache();
                $this->access->ensureCanCreateInWorkspace($actor, $lockedWorkspace->uid);

                if ($command->parentPageUid !== null) {
                    // Grant revocation, parent moves, and parent deletion all serialize on the
                    // parent row locked above. Validate the returned locked state with the same
                    // freshly loaded authority before persisting the hierarchy relationship.
                    $this->ensureLockedParentPageIsAvailable($actor, $lockedParent, $lockedWorkspace->uid);
                }

                // Re-check owner eligibility under the workspace lock. The pre-transaction
                // check above is optimistic: a concurrent role change or member removal --
                // which take this same workspace row lock ahead of their membership write
                // (the F6 lock protocol) -- can downgrade the owner to Reader or remove them
                // between that read and here. Rechecking under the lock serialises against
                // those handlers, so a new page can never commit owned by a Reader or a
                // non-member. (Update and move already recheck under the page/workspace lock;
                // a create has no page row for the membership handlers to contend on, so the
                // workspace lock is the coordinating point.)
                $this->metadataRules->ensureOwnerBelongsToWorkspace($ownerUserUid, $lockedWorkspace->uid);
                $this->storageQuota->ensureWorkspaceAllowsNewBytes($lockedWorkspace, $byteSize);
                $this->storageQuota->ensurePageAllowsNewBytes(null, $byteSize);
                $slug = $this->slugs->uniqueForWorkspace($workspace->uid, $title);
                $categoryUid = $command->categoryUid;

                if ($command->categoryName !== null) {
                    try {
                        $categoryUid = $this->createCategory->handle($actor, new CreateCategoryCommand(
                            workspaceUid: $lockedWorkspace->uid,
                            name: $command->categoryName,
                        ))->uid;
                    } catch (DomainRuleViolation $exception) {
                        throw new CategoryRuleViolation($exception->getMessage(), 0, $exception);
                    }
                }

                $page = Page::query()->forceCreate([
                    'workspace_uid' => $workspace->uid,
                    'owner_user_uid' => $ownerUserUid,
                    'parent_page_uid' => $command->parentPageUid,
                    'category_uid' => $categoryUid,
                    'title' => $title,
                    'slug' => $slug,
                    'description' => $description,
                    'type' => $command->type,
                    'status' => $command->status,
                ]);

                $this->recordPageCreated($page, $actorUid, count($command->tagNames));
                $version = $this->versionWriter->writeInitialVersion(
                    page: $page,
                    prepared: $prepared,
                    source: $command->source,
                    actorUid: $actorUid,
                    provenance: $command->provenance,
                    changeSummary: $changeSummary,
                );
                $storagePaths = $this->versionStorage->paths($version);
                $page->forceFill(['current_version_uid' => $version->uid])->save();
                $this->tags->sync($page, $command->tagNames, $actorUid);
                $this->searchVectors->refreshPage($page->uid);

                if ($scan->hasWarningFindings()) {
                    $this->securityWarnings->record($page, $version, $actorUid, $scan);
                }

                $refreshed = $page->refresh();
                $transactionCallbackCompleted = true;

                return $refreshed;
            });
        } catch (Throwable $exception) {
            // Once the callback completed, commit acknowledgement may have been
            // lost after PostgreSQL committed. Preserve every promoted path in
            // that ambiguous state; genuine post-callback rollbacks leave only
            // orphans, which the age-gated orphan reaper can delete safely.
            if (!$transactionCallbackCompleted && $storagePaths !== []) {
                Storage::disk('artifacts')->delete($storagePaths);
            }

            throw $exception;
        } finally {
            $prepared->discardStaging();
        }

        $this->publishCatalogUpdate($page);

        return $page;
    }

    private function ensureCategoryInputIsUnambiguous(CreatePageCommand $command): void
    {
        if ($command->categoryUid !== null && $command->categoryName !== null) {
            throw new CategoryRuleViolation('Select an existing category or create a new one, not both.');
        }
    }

    private function workspace(string $workspaceUid): Workspace
    {
        $workspace = Workspace::query()->find($workspaceUid);

        if (!$workspace instanceof Workspace) {
            throw new DomainRuleViolation('Workspace does not exist.');
        }

        return $workspace;
    }

    private function ensureDescriptionIsSafe(
        User $actor,
        Workspace $workspace,
        PageType $pageType,
        ?string $description,
    ): void {
        if ($description === null) {
            return;
        }

        $scan = $this->scanner->scanDescription($description);

        if (!$scan->hasBlockedFindings()) {
            return;
        }

        $this->recordBlockedScan->forPageCreation(
            actor: $actor,
            workspace: $workspace,
            pageType: $pageType,
            findingCodes: $scan->blockedCodes(),
            operation: 'create_page_description',
        );

        throw new BlockedPageContentException($scan->blockedCodes());
    }

    private function resolveOwnerUserUid(string $actorUid, CreatePageCommand $command): string
    {
        if ($command->ownerUserUid === null || $command->ownerUserUid === '') {
            return $actorUid;
        }

        return $command->ownerUserUid;
    }

    private function ensureStatusCanBeUsedForNewPage(PageStatus $status): void
    {
        if (!$status->canStartNewPage()) {
            throw new DomainRuleViolation('New pages must start as draft or approved.');
        }
    }

    private function ensureParentPageIsAvailable(
        User $actor,
        ?string $parentPageUid,
        string $workspaceUid,
    ): void {
        if ($parentPageUid === null) {
            return;
        }

        $parent = Page::query()
            ->where('uid', $parentPageUid)
            ->where('workspace_uid', $workspaceUid)
            ->first();

        if (!$parent instanceof Page || !$this->access->canView($actor, $parent)) {
            throw new DomainRuleViolation('Parent page must belong to the selected workspace.');
        }
    }

    private function ensureLockedParentPageIsAvailable(User $actor, ?Page $parent, string $workspaceUid): void
    {
        if (
            !$parent instanceof Page
            || $parent->workspace_uid !== $workspaceUid
            || !$this->access->canView($actor, $parent)
        ) {
            throw new DomainRuleViolation('Parent page must belong to the selected workspace.');
        }
    }

    private function recordPageCreated(Page $page, string $actorUid, int $submittedTagCount): void
    {
        $mcpMetadata = $this->mcpContext->auditMetadata();
        $event = $this->events->record(
            eventType: DomainEventType::PageCreated,
            aggregateType: 'page',
            aggregateUid: $page->uid,
            payload: [
                'page_uid' => $page->uid,
                'workspace_uid' => $page->workspace_uid,
                'owner_user_uid' => $page->owner_user_uid,
                'created_by_user_uid' => $actorUid,
                'page_type' => $page->type->value,
                'status' => $page->status->value,
            ] + $mcpMetadata,
        );

        $this->audit->record(
            event: $event,
            actorUserUid: $actorUid,
            auditableType: 'page',
            auditableUid: $page->uid,
            action: DomainEventType::PageCreated,
            summary: 'Page created.',
            metadata: [
                'workspace_uid' => $page->workspace_uid,
                'page_type' => $page->type->value,
                'status' => $page->status->value,
                'submitted_tag_count' => $submittedTagCount,
            ] + $mcpMetadata,
        );
    }

    private function publishCatalogUpdate(Page $page): void
    {
        if (!$this->realtimeConfiguration->enabled()) {
            return;
        }

        try {
            $recipientUserUids = $this->liveAudience->forWorkspace($page->workspace_uid);

            if ($recipientUserUids === []) {
                return;
            }

            event(new AccessiblePageCreated(
                pageUid: $page->uid,
                workspaceUid: $page->workspace_uid,
                recipientUserUids: $recipientUserUids,
            ));
        } catch (Throwable $exception) {
            // Realtime is observational. A committed page must stay successful
            // even when recipient resolution or Reverb delivery is unavailable.
            report($exception);
        }
    }
}
