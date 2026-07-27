<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Audit\AuditLogger;
use App\Application\Events\DomainEventRecorder;
use App\Application\Identity\ActorId;
use App\Application\Mcp\McpRequestContext;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageMetadataException;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\Page;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Narrow metadata mutation used by MCP image description enrichment. It never
 * re-submits or revalidates title, hierarchy, owner, category, or tags.
 */
final readonly class UpdatePageDescription
{
    public function __construct(
        private PageAccess $access,
        private PageMetadataRules $metadataRules,
        private PageContentScanner $scanner,
        private RecordBlockedPageContentScan $recordBlockedScan,
        private PageSearchVectorUpdater $searchVectors,
        private DomainEventRecorder $events,
        private AuditLogger $audit,
        private McpRequestContext $mcpContext,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, UpdatePageDescriptionCommand $command): Page
    {
        $page = PageFinder::requireByUid($command->pageUid);

        if (!$this->access->canEdit($actor, $page)) {
            throw new AuthorizationException('You cannot edit this page.');
        }

        $description = $this->metadataRules->normalizeDescription($command->description);
        $scan = $description === null ? null : $this->scanner->scanDescription($description);

        if ($scan?->hasBlockedFindings() === true) {
            $this->recordBlockedScan->forPageMetadata($actor, $page, $scan->blockedCodes());

            throw new BlockedPageContentException($scan->blockedCodes());
        }

        $actorUid = ActorId::fromUser($actor);

        return DB::transaction(function () use ($actor, $actorUid, $command, $description): Page {
            $page = $this->access->lockAndReauthorize($command->pageUid, function (Page $lockedPage) use ($actor): void {
                if (!$this->access->canEdit($actor, $lockedPage)) {
                    throw new AuthorizationException('You cannot edit this page.');
                }
            });

            if ($page->current_version_uid !== $command->expectedCurrentVersionUid) {
                throw new StalePageVersionException(
                    currentVersionUid: (string) $page->current_version_uid,
                    submittedBaseVersionUid: $command->expectedCurrentVersionUid,
                );
            }

            if ($page->metadata_revision !== $command->expectedMetadataRevision) {
                throw new StalePageMetadataException(
                    currentRevision: $page->metadata_revision,
                    submittedRevision: $command->expectedMetadataRevision,
                );
            }

            if ($page->description === $description) {
                return $page->refresh();
            }

            $page->forceFill([
                'description' => $description,
                'metadata_revision' => $page->metadata_revision + 1,
            ])->save();
            $this->searchVectors->refreshPage($page->uid);

            $tagCount = $page->tags()->count();
            $metadata = [
                'workspace_uid' => $page->workspace_uid,
                'changed_fields' => 'description',
                'tag_count' => $tagCount,
            ] + $this->mcpContext->auditMetadata();
            $event = $this->events->record(
                eventType: DomainEventType::PageMetadataUpdated,
                aggregateType: 'page',
                aggregateUid: $page->uid,
                payload: [
                    'page_uid' => $page->uid,
                    'workspace_uid' => $page->workspace_uid,
                    'updated_by_user_uid' => $actorUid,
                    'changed_fields' => 'description',
                    'tag_count' => $tagCount,
                ] + $this->mcpContext->auditMetadata(),
            );

            $this->audit->record(
                event: $event,
                actorUserUid: $actorUid,
                auditableType: 'page',
                auditableUid: $page->uid,
                action: DomainEventType::PageMetadataUpdated,
                summary: 'Page metadata updated.',
                metadata: $metadata,
            );

            return $page->refresh();
        });
    }
}
