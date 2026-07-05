<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Domain\DomainRuleViolation;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class WorkspaceStorageQuota
{
    public function __construct(
        private InstallationLimitSettings $limits,
    ) {
    }

    public function lockWorkspaceForStorageUpdate(string $workspaceUid): Workspace
    {
        $workspace = Workspace::query()
            ->whereKey($workspaceUid)
            ->lockForUpdate()
            ->first();

        if (!$workspace instanceof Workspace) {
            throw new DomainRuleViolation('Workspace does not exist.');
        }

        return $workspace;
    }

    /**
     * Reads the maintained workspaces.used_storage_bytes counter, so callers
     * must pass a workspace locked through lockWorkspaceForStorageUpdate().
     */
    public function ensureWorkspaceAllowsNewBytes(Workspace $workspace, int $newBytes): void
    {
        $limit = $this->positiveLimit('pages.max_workspace_storage_bytes', 'workspace storage');

        if ($workspace->used_storage_bytes + $newBytes > $limit) {
            throw new DomainRuleViolation('Workspace page storage quota exceeded.');
        }
    }

    /**
     * Append-path variant of the workspace quota check. Subtracts the bytes this
     * append is guaranteed to reclaim by pruning $pageUid's oldest surplus
     * versions, so a workspace near its limit is not wedged by bytes the same
     * transaction releases -- the workspace sibling of the per-page retention-aware
     * check. Must run under the page + workspace row locks the append already
     * holds: it reads the maintained used_storage_bytes counter and the page's
     * version rows. Creation and workspace-move keep the prune-blind check above --
     * they add or relocate whole pages, not roll a single page's oldest version off.
     */
    public function ensureWorkspaceAllowsNewBytesForVersionAppend(
        Workspace $workspace,
        string $pageUid,
        int $newBytes,
    ): void {
        $limit = $this->positiveLimit('pages.max_workspace_storage_bytes', 'workspace storage');
        $projectedUsage = max(0, $workspace->used_storage_bytes - $this->prunablePageBytes($pageUid));

        if ($projectedUsage + $newBytes > $limit) {
            throw new DomainRuleViolation('Workspace page storage quota exceeded.');
        }
    }

    /**
     * Adds stored page-version bytes to the workspace counter. Must run inside
     * the transaction that persists the version, under the workspace row lock.
     */
    public function recordBytesStored(string $workspaceUid, int $bytes): void
    {
        if ($bytes === 0) {
            return;
        }

        Workspace::query()
            ->whereKey($workspaceUid)
            ->increment('used_storage_bytes', $bytes);
    }

    /**
     * Releases page-version bytes from the workspace counter. Must run inside
     * the transaction that removes the versions, under the workspace row lock.
     */
    public function recordBytesReleased(string $workspaceUid, int $bytes): void
    {
        if ($bytes === 0) {
            return;
        }

        Workspace::query()
            ->whereKey($workspaceUid)
            ->decrement('used_storage_bytes', $bytes);
    }

    public function storedPageBytes(Page $page): int
    {
        return (int) PageVersion::query()
            ->where('page_uid', $page->uid)
            ->sum('byte_size');
    }

    public function ensurePageAllowsNewBytes(?string $pageUid, int $newBytes): void
    {
        $limit = $this->positiveLimit('pages.max_page_storage_bytes', 'page storage');
        $usedBytes = $pageUid === null
            ? 0
            : $this->retainedPageBytes($pageUid);

        if ($usedBytes + $newBytes > $limit) {
            throw new DomainRuleViolation('Page storage quota exceeded.');
        }
    }

    /**
     * Bytes of the existing versions that will still exist after the append this
     * check guards and the retention prune it triggers. The append adds one
     * version and PageVersionPruner rolls the oldest surplus off the end, so a
     * page already at the version cap must not be wedged by bytes the same
     * operation reclaims: only versions that outlive the append count against the
     * per-page quota. Below the cap nothing prunes and every version is counted.
     */
    /**
     * Bytes of $pageUid's existing versions that the retention prune triggered by
     * this append will roll off the end -- everything except the newest versions
     * that survive (see retainedPageBytes). These bytes are released from the
     * workspace counter in the same transaction, so the append-path workspace
     * projection may discount them.
     */
    private function prunablePageBytes(string $pageUid): int
    {
        $totalBytes = (int) PageVersion::query()
            ->where('page_uid', $pageUid)
            ->sum('byte_size');

        return $totalBytes - $this->retainedPageBytes($pageUid);
    }

    private function retainedPageBytes(string $pageUid): int
    {
        $versionLimit = $this->limits->integer('pages.max_page_versions');

        // A non-positive retention limit disables pruning (mirrors
        // PageVersionPruner), so no existing version is ever reclaimed.
        if ($versionLimit < 1) {
            return (int) PageVersion::query()
                ->where('page_uid', $pageUid)
                ->sum('byte_size');
        }

        // The newcomer consumes one retained slot, so at most versionLimit - 1 of
        // the existing (newest-first) versions survive the prune; older versions
        // are pruned by the same append and must not count here.
        $retainedExisting = $versionLimit - 1;

        if ($retainedExisting < 1) {
            return 0;
        }

        // Limit the newest rows via a subquery so the row cap constrains which
        // rows are summed, not the aggregate result.
        $newestRetained = PageVersion::query()
            ->select('byte_size')
            ->where('page_uid', $pageUid)
            ->orderByDesc('version_number')
            ->limit($retainedExisting);

        return (int) DB::query()->fromSub($newestRetained, 'retained')->sum('byte_size');
    }

    private function positiveLimit(string $key, string $label): int
    {
        $limit = $this->limits->integer($key);

        if ($limit < 1) {
            throw new LogicException(sprintf('Configured %s limit must be positive.', $label));
        }

        return $limit;
    }
}
