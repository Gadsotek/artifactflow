<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Identity\ActorId;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class UpdatePageContent
{
    public function __construct(
        private PageAccess $access,
        private PageVersionAppender $versions,
        private RecordBlockedPageContentScan $recordBlockedScan,
        private PageVersionPruner $versionPruner,
        private ArtifactContentDeleter $artifactContentDeleter,
    ) {
    }

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, UpdatePageContentCommand $command): PageVersion
    {
        $page = PageFinder::requireByUid($command->pageUid);

        if (!$this->access->canEdit($actor, $page)) {
            throw new AuthorizationException('You cannot edit this page.');
        }

        $actorUid = ActorId::fromUser($actor);
        $prunedStoragePaths = [];

        try {
            $version = DB::transaction(function () use ($actor, $actorUid, $command, &$prunedStoragePaths): PageVersion {
                // Re-authorize under the page row lock with fresh authority. canEdit()
                // above ran before the lock and against PageAccess's request-scoped
                // cache, so a revocation that committed while this request waited for
                // the lock would otherwise let a now-unauthorized write through. Locking
                // first and re-reading closes that window.
                $lockedPage = $this->lockAndReauthorizeForEdit($actor, $command->pageUid);

                $version = $this->versions->append(
                    actor: $actor,
                    page: $lockedPage,
                    content: $command->content,
                    source: $command->source,
                    baseVersionUid: $command->baseVersionUid,
                );

                $prunedStoragePaths = $this->versionPruner->pruneToCap($lockedPage, $actorUid);

                return $version;
            });
        } catch (BlockedPageContentException $exception) {
            $this->recordBlockedScan->forPageVersion($actor, $page, $exception->findingCodes());

            throw $exception;
        }

        $this->deletePrunedArtifacts($page->uid, $prunedStoragePaths);

        return $version;
    }

    /**
     * Re-fetch the page under a row lock and re-check edit authority against fresh,
     * un-cached state. This is the authorization of record for the write: the
     * pre-transaction canEdit() is only a fast fail, and a membership or grant
     * revoked while this request waited for the lock must still block the append.
     *
     * @throws AuthorizationException
     */
    private function lockAndReauthorizeForEdit(User $actor, string $pageUid): Page
    {
        return $this->access->lockAndReauthorize($pageUid, function (Page $page) use ($actor): void {
            if (!$this->access->canEdit($actor, $page)) {
                throw new AuthorizationException('You cannot edit this page.');
            }
        });
    }

    /**
     * Best-effort cleanup of pruned version blobs, after the retention prune has
     * committed. A failure here leaves an orphaned file (no referencing row),
     * which PruneOrphanArtifacts reaps; it never leaves a dangling reference.
     *
     * @param list<string> $storagePaths
     */
    private function deletePrunedArtifacts(string $pageUid, array $storagePaths): void
    {
        if ($storagePaths === []) {
            return;
        }

        if (!$this->artifactContentDeleter->deleteMany($storagePaths)) {
            Log::warning('page.version.prune.artifact_delete_failed', [
                'page_uid' => $pageUid,
                'storage_path_count' => count($storagePaths),
            ]);
        }
    }
}
