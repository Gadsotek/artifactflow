<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\RealtimeConfiguration;
use App\Application\Identity\ActorId;
use App\Domain\DomainRuleViolation;
use App\Domain\Events\DomainEventType;
use App\Domain\PageCatalog\InvalidPageStatusTransition;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Events\PageContentVersionChanged;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class PageVersionAppender
{
    public function __construct(
        private PageContentPreparer $contentPreparer,
        private PageSecurityWarningRecorder $securityWarnings,
        private PageStatusChanger $statusChanger,
        private PageSearchVectorUpdater $searchVectors,
        private WorkspaceStorageQuota $storageQuota,
        private PageVersionWriter $versionWriter,
        private RealtimeConfiguration $realtimeConfiguration,
    ) {
    }

    public function append(
        User $actor,
        Page $page,
        string $content,
        PageVersionSource $source,
        ?string $baseVersionUid = null,
        ?string $expectedCurrentVersionUid = null,
    ): PageVersion {
        return $this->prepare($actor, $page, $content, $source)->append(
            page: $page,
            baseVersionUid: $baseVersionUid,
            expectedCurrentVersionUid: $expectedCurrentVersionUid,
        );
    }

    public function prepare(
        User $actor,
        Page $page,
        string $content,
        PageVersionSource $source,
    ): PreparedPageVersionAppend {
        $this->ensurePageAcceptsContentChanges($page);
        $actorUid = ActorId::fromUser($actor);
        $prepared = $this->contentPreparer->prepare($page->type, $content, $actorUid, $source);

        return new PreparedPageVersionAppend(
            fn (
                Page $lockedPage,
                ?string $baseVersionUid,
                ?string $expectedCurrentVersionUid,
            ): PageVersion => $this->appendPrepared(
                actor: $actor,
                page: $lockedPage,
                prepared: $prepared,
                source: $source,
                baseVersionUid: $baseVersionUid,
                expectedCurrentVersionUid: $expectedCurrentVersionUid,
            ),
        );
    }

    private function appendPrepared(
        User $actor,
        Page $page,
        PreparedPageContent $prepared,
        PageVersionSource $source,
        ?string $baseVersionUid = null,
        ?string $expectedCurrentVersionUid = null,
    ): PageVersion {
        $actorUid = ActorId::fromUser($actor);
        $page = $this->lockPageForVersionAppend($page);
        $this->ensurePageAcceptsContentChanges($page);
        $this->ensureExpectedCurrentVersion($page, $expectedCurrentVersionUid);
        $this->ensureBaseVersionIsCurrent($page, $baseVersionUid, $source);
        $lockedWorkspace = $this->storageQuota->lockWorkspaceForStorageUpdate($page->workspace_uid);
        $this->storageQuota->ensureWorkspaceAllowsNewBytesForVersionAppend(
            $lockedWorkspace,
            $page->uid,
            strlen($prepared->content),
        );
        $this->storageQuota->ensurePageAllowsNewBytes($page->uid, strlen($prepared->content));

        $version = null;
        try {
            $version = $this->versionWriter->appendVersion(
                page: $page,
                prepared: $prepared,
                source: $source,
                actorUid: $actorUid,
            );
            $page->forceFill(['current_version_uid' => $version->uid])->save();
            $this->returnContentChangedPageToDraft($actor, $page);
            $this->searchVectors->refreshPage($page->uid);

            if ($prepared->scan->hasWarningFindings()) {
                $this->securityWarnings->record($page, $version, $actorUid, $prepared->scan);
            }

            if ($this->realtimeConfiguration->enabled()) {
                event(new PageContentVersionChanged(
                    pageUid: $page->uid,
                    versionUid: $version->uid,
                    versionNumber: $version->version_number,
                ));
            }

            return $version;
        } catch (Throwable $exception) {
            if ($version instanceof PageVersion) {
                Storage::disk('artifacts')->delete($version->content_storage_path);
            }

            throw $exception;
        }
    }

    private function lockPageForVersionAppend(Page $page): Page
    {
        $lockedPage = Page::query()
            ->whereKey($page->uid)
            ->lockForUpdate()
            ->first();

        if (!$lockedPage instanceof Page) {
            throw new DomainRuleViolation('Page does not exist.');
        }

        return $lockedPage;
    }

    private function ensurePageAcceptsContentChanges(Page $page): void
    {
        if ($page->status === PageStatus::Archived) {
            throw new InvalidPageStatusTransition(
                'Archived pages must be unarchived before changing content.',
            );
        }
    }

    /**
     * Optimistic-concurrency assertion checked against the freshly locked page,
     * independent of $source. Revert-to-previous threads the version it observed
     * as current so a save that committed in the gap is rejected here with a 409
     * rather than silently overwritten -- the Restore source skips the base-version
     * check below, so the guarantee cannot ride on that path.
     */
    private function ensureExpectedCurrentVersion(Page $page, ?string $expectedCurrentVersionUid): void
    {
        if ($expectedCurrentVersionUid === null || $page->current_version_uid === $expectedCurrentVersionUid) {
            return;
        }

        throw new StalePageVersionException(
            currentVersionUid: (string) $page->current_version_uid,
            submittedBaseVersionUid: $expectedCurrentVersionUid,
        );
    }

    private function ensureBaseVersionIsCurrent(
        Page $page,
        ?string $baseVersionUid,
        PageVersionSource $source,
    ): void {
        if ($source === PageVersionSource::Restore || $page->current_version_uid === null) {
            return;
        }

        if ($baseVersionUid === $page->current_version_uid) {
            return;
        }

        throw new StalePageVersionException(
            currentVersionUid: $page->current_version_uid,
            submittedBaseVersionUid: $baseVersionUid,
        );
    }

    private function returnContentChangedPageToDraft(User $actor, Page $page): void
    {
        if (!$page->status->returnsToDraftAfterContentChange()) {
            return;
        }

        $this->statusChanger->change(
            actor: $actor,
            page: $page,
            newStatus: PageStatus::Draft,
            eventType: DomainEventType::PageContentChangeReturnedToDraft,
            summary: 'Page returned to draft after content changed.',
        );
    }
}
