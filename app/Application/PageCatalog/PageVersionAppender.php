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
use App\Domain\PageCatalog\Security\BlockedPageContentException;
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
        private PageContentScanner $scanner,
        private PageContentRules $contentRules,
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
        $actorUid = ActorId::fromUser($actor);
        $this->ensurePageAcceptsContentChanges($page);
        $normalizedContent = $this->contentRules->normalize($page->type, $content);
        $this->contentRules->ensureHtmlDocumentContent($page->type, $normalizedContent);
        $this->contentRules->ensureFitsConfiguredLimit($page->type, $normalizedContent);

        $scan = $this->scanner->scan($page->type, $normalizedContent);

        if ($scan->hasBlockedFindings()) {
            throw new BlockedPageContentException($scan->blockedCodes());
        }

        $page = $this->lockPageForVersionAppend($page);
        $this->ensurePageAcceptsContentChanges($page);
        $this->ensureExpectedCurrentVersion($page, $expectedCurrentVersionUid);
        $this->ensureBaseVersionIsCurrent($page, $baseVersionUid, $source);
        $lockedWorkspace = $this->storageQuota->lockWorkspaceForStorageUpdate($page->workspace_uid);
        $this->storageQuota->ensureWorkspaceAllowsNewBytesForVersionAppend($lockedWorkspace, $page->uid, strlen($normalizedContent));
        $this->storageQuota->ensurePageAllowsNewBytes($page->uid, strlen($normalizedContent));

        $version = null;
        try {
            $version = $this->versionWriter->appendVersion(
                page: $page,
                content: $normalizedContent,
                scan: $scan,
                source: $source,
                actorUid: $actorUid,
            );
            $page->forceFill(['current_version_uid' => $version->uid])->save();
            $this->returnContentChangedPageToDraft($actor, $page);
            $this->searchVectors->refreshPage($page->uid);

            if ($scan->hasWarningFindings()) {
                $this->securityWarnings->record($page, $version, $actorUid, $scan);
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
