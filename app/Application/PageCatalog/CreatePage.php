<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Application\Mcp\McpRequestContext;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\CategoryRuleViolation;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
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
        private PageContentRules $contentRules,
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
        private CreateCategory $createCategory,
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
        $content = $this->contentRules->normalize($command->type, $command->content);
        $ownerUserUid = $this->resolveOwnerUserUid($actorUid, $command);
        $this->metadataRules->ensureOwnerBelongsToWorkspace($ownerUserUid, $workspace->uid);
        $this->ensureStatusCanBeUsedForNewPage($command->status);
        $this->ensureSourceFilenameIsAllowed($command->type, $command->sourceFilename);
        $this->contentRules->ensureHtmlDocumentContent($command->type, $content);
        $this->contentRules->ensureFitsConfiguredLimit($command->type, $content);
        $this->ensureCategoryInputIsUnambiguous($command);
        $this->metadataRules->ensureCategoryBelongsToWorkspace($command->categoryUid, $workspace->uid);
        $this->ensureParentPageIsAvailable($actor, $command->parentPageUid, $workspace->uid);

        $scan = $this->scanner->scan($command->type, $content);

        if ($scan->hasBlockedFindings()) {
            $this->recordBlockedScan->forPageCreation(
                actor: $actor,
                workspace: $workspace,
                pageType: $command->type,
                findingCodes: $scan->blockedCodes(),
            );

            throw new BlockedPageContentException($scan->blockedCodes());
        }

        $storagePath = null;
        $closureCompleted = false;

        try {
            return DB::transaction(function () use (
                $actorUid,
                $actor,
                $command,
                $content,
                $description,
                $ownerUserUid,
                $scan,
                &$storagePath,
                &$closureCompleted,
                $title,
                $workspace,
            ): Page {
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
                $this->storageQuota->ensureWorkspaceAllowsNewBytes($lockedWorkspace, strlen($content));
                $this->storageQuota->ensurePageAllowsNewBytes(null, strlen($content));
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
                    content: $content,
                    scan: $scan,
                    source: $command->source,
                    actorUid: $actorUid,
                );
                $storagePath = $version->content_storage_path;
                $page->forceFill(['current_version_uid' => $version->uid])->save();
                $this->tags->sync($page, $command->tagNames, $actorUid);
                $this->searchVectors->refreshPage($page->uid);

                if ($scan->hasWarningFindings()) {
                    $this->securityWarnings->record($page, $version, $actorUid, $scan);
                }

                $refreshed = $page->refresh();
                $closureCompleted = true;

                return $refreshed;
            });
        } catch (Throwable $exception) {
            // Clean up the staged blob only when the closure itself failed, i.e. the
            // transaction rolled back. A failure raised by the commit after the
            // closure completed leaves the version row durable, so deleting its blob
            // would strand the page's current version; let PruneOrphanArtifacts sweep
            // any post-commit anomaly instead (matching UpdatePageContent).
            if (!$closureCompleted && $storagePath !== null) {
                Storage::disk('artifacts')->delete($storagePath);
            }

            throw $exception;
        }
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

    private function ensureSourceFilenameIsAllowed(PageType $type, ?string $sourceFilename): void
    {
        if ($type !== PageType::HtmlArtifact || $sourceFilename === null) {
            return;
        }

        if (strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION)) !== 'html') {
            throw new DomainRuleViolation('HTML artifact uploads must use a .html file.');
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
}
