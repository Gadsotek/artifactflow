<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class RevertToPreviousVersion
{
    public function __construct(
        private PageAccess $access,
        private RestorePageVersion $restorePageVersion,
    ) {
    }

    /**
     * Restores the version immediately preceding the submitted base version,
     * guarded by optimistic concurrency on the page's current version.
     *
     * @throws AuthorizationException
     * @throws StalePageVersionException when the base version is no longer current
     * @throws DomainRuleViolation when the base version does not belong to the page or nothing precedes it
     */
    public function handle(User $actor, RevertToPreviousVersionCommand $command): RevertToPreviousVersionResult
    {
        $page = PageFinder::requireByUid($command->pageUid);

        if (!$this->access->canEdit($actor, $page)) {
            throw new AuthorizationException('You cannot edit this page.');
        }

        if ($page->current_version_uid !== $command->baseVersionUid) {
            throw new StalePageVersionException(
                currentVersionUid: (string) $page->current_version_uid,
                submittedBaseVersionUid: $command->baseVersionUid,
            );
        }

        $baseVersion = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->where('uid', $command->baseVersionUid)
            ->first();

        if (!$baseVersion instanceof PageVersion) {
            throw new DomainRuleViolation('The submitted base_version_uid is not a version of this page.');
        }

        $previousVersion = PageVersion::query()
            ->where('page_uid', $page->uid)
            ->where('version_number', '<', $baseVersion->version_number)
            ->orderByDesc('version_number')
            ->first();

        if (!$previousVersion instanceof PageVersion) {
            throw new DomainRuleViolation('This page has no previous version to restore.');
        }

        $restoredVersion = $this->restorePageVersion->handle($actor, new RestorePageVersionCommand(
            pageUid: $page->uid,
            versionUid: $previousVersion->uid,
            // Re-assert the observed current version under the append's page lock:
            // the unlocked check above is a fast fail, but a save committed between
            // it and the lock must surface as a 409, not silently overwrite.
            expectedCurrentVersionUid: $command->baseVersionUid,
        ));

        return new RevertToPreviousVersionResult(
            restoredVersion: $restoredVersion,
            restoredFromVersion: $previousVersion,
        );
    }
}
