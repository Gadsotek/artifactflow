<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Application\Identity\EffectiveWorkspaceMembershipResolver;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\PageVersionDerivative;
use App\Models\User;
use App\Models\Workspace;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class InstallationUsageOverview
{
    public function __construct(
        private InstallationLimitSettings $limits,
        private ByteSizeFormatter $bytes,
        private EffectiveWorkspaceMembershipResolver $memberships,
    ) {
    }

    public function overview(User $actor): InstallationStorageUsage
    {
        $limitValues = $this->limits->current();
        $usedBytes = (int) PageVersion::query()->sum('byte_size')
            + (int) PageVersionDerivative::query()->sum('byte_size');
        $visibleWorkspaceUids = $this->memberships->workspaceUidsFor($actor->uid);

        return new InstallationStorageUsage(
            summary: new StorageUsageSummary(
                workspaceCount: Workspace::query()->count(),
                pageCount: Page::query()->count(),
                versionCount: PageVersion::query()->count(),
                usedBytes: $usedBytes,
                usedBytesLabel: $this->bytes->format($usedBytes),
            ),
            workspaces: $this->workspaceUsage($limitValues->maxWorkspaceStorageBytes, $visibleWorkspaceUids),
            pages: $this->pageUsage($limitValues->maxPageStorageBytes, $visibleWorkspaceUids),
        );
    }

    /**
     * @param list<string> $visibleWorkspaceUids
     *
     * @return list<WorkspaceStorageUsageItem>
     */
    private function workspaceUsage(int $workspaceLimitBytes, array $visibleWorkspaceUids): array
    {
        if ($visibleWorkspaceUids === []) {
            return [];
        }

        /** @var Collection<int, Model> $rows */
        $rows = Workspace::query()
            ->select([
                'workspaces.uid',
                'workspaces.name',
                'workspaces.type',
            ])
            ->selectRaw(<<<'SQL'
                COALESCE((
                    SELECT SUM(usage_versions.byte_size)
                    FROM page_versions AS usage_versions
                    INNER JOIN pages AS usage_pages ON usage_pages.uid = usage_versions.page_uid
                    WHERE usage_pages.workspace_uid = workspaces.uid
                ), 0) + COALESCE((
                    SELECT SUM(usage_derivatives.byte_size)
                    FROM page_version_derivatives AS usage_derivatives
                    INNER JOIN page_versions AS derivative_versions
                        ON derivative_versions.uid = usage_derivatives.page_version_uid
                    INNER JOIN pages AS derivative_pages ON derivative_pages.uid = derivative_versions.page_uid
                    WHERE derivative_pages.workspace_uid = workspaces.uid
                ), 0) AS used_bytes
                SQL)
            ->selectRaw(<<<'SQL'
                (SELECT COUNT(*) FROM pages AS counted_pages
                    WHERE counted_pages.workspace_uid = workspaces.uid) AS page_count
                SQL)
            ->selectRaw(<<<'SQL'
                (SELECT COUNT(*) FROM page_versions AS counted_versions
                    INNER JOIN pages AS counted_version_pages ON counted_version_pages.uid = counted_versions.page_uid
                    WHERE counted_version_pages.workspace_uid = workspaces.uid) AS version_count
                SQL)
            ->whereIn('workspaces.uid', $visibleWorkspaceUids)
            ->orderByDesc('used_bytes')
            ->orderBy('workspaces.name')
            ->get();

        return array_values($rows
            ->map(function (Model $row) use ($workspaceLimitBytes): WorkspaceStorageUsageItem {
                $usedBytes = $this->intAttribute($row, 'used_bytes');
                $usagePercent = $this->usagePercent($usedBytes, $workspaceLimitBytes);

                return new WorkspaceStorageUsageItem(
                    uid: $this->stringAttribute($row, 'uid'),
                    name: $this->stringAttribute($row, 'name'),
                    type: $this->stringValue($row->getAttribute('type')),
                    pageCount: $this->intAttribute($row, 'page_count'),
                    versionCount: $this->intAttribute($row, 'version_count'),
                    usedBytes: $usedBytes,
                    usedBytesLabel: $this->bytes->format($usedBytes),
                    limitBytes: $workspaceLimitBytes,
                    limitBytesLabel: $this->bytes->format($workspaceLimitBytes),
                    percentUsedLabel: $this->percentLabel($usagePercent, $usedBytes),
                    progressPercent: $this->progressPercent($usagePercent, $usedBytes),
                    ariaPercent: $this->ariaPercent($usagePercent, $usedBytes),
                );
            })
            ->all());
    }

    /**
     * @param list<string> $visibleWorkspaceUids
     *
     * @return list<PageStorageUsageItem>
     */
    private function pageUsage(int $pageLimitBytes, array $visibleWorkspaceUids): array
    {
        if ($visibleWorkspaceUids === []) {
            return [];
        }

        /** @var Collection<int, Model> $rows */
        $rows = Page::query()
            ->join('workspaces', 'workspaces.uid', '=', 'pages.workspace_uid')
            ->select([
                'pages.uid',
                'pages.title',
                'workspaces.name as workspace_name',
            ])
            ->selectRaw(<<<'SQL'
                COALESCE((SELECT SUM(usage_versions.byte_size)
                    FROM page_versions AS usage_versions
                    WHERE usage_versions.page_uid = pages.uid), 0)
                + COALESCE((SELECT SUM(usage_derivatives.byte_size)
                    FROM page_version_derivatives AS usage_derivatives
                    INNER JOIN page_versions AS derivative_versions
                        ON derivative_versions.uid = usage_derivatives.page_version_uid
                    WHERE derivative_versions.page_uid = pages.uid), 0) AS used_bytes
                SQL)
            ->selectRaw(<<<'SQL'
                (SELECT COUNT(*) FROM page_versions AS counted_versions
                    WHERE counted_versions.page_uid = pages.uid) AS version_count
                SQL)
            ->whereIn('pages.workspace_uid', $visibleWorkspaceUids)
            ->orderByDesc('used_bytes')
            ->orderBy('pages.title')
            ->limit(10)
            ->get();

        return array_values($rows
            ->map(function (Model $row) use ($pageLimitBytes): PageStorageUsageItem {
                $usedBytes = $this->intAttribute($row, 'used_bytes');
                $usagePercent = $this->usagePercent($usedBytes, $pageLimitBytes);

                return new PageStorageUsageItem(
                    uid: $this->stringAttribute($row, 'uid'),
                    title: $this->stringAttribute($row, 'title'),
                    workspaceName: $this->stringAttribute($row, 'workspace_name'),
                    versionCount: $this->intAttribute($row, 'version_count'),
                    usedBytes: $usedBytes,
                    usedBytesLabel: $this->bytes->format($usedBytes),
                    limitBytes: $pageLimitBytes,
                    limitBytesLabel: $this->bytes->format($pageLimitBytes),
                    percentUsedLabel: $this->percentLabel($usagePercent, $usedBytes),
                    progressPercent: $this->progressPercent($usagePercent, $usedBytes),
                    ariaPercent: $this->ariaPercent($usagePercent, $usedBytes),
                );
            })
            ->all());
    }

    private function usagePercent(int $usedBytes, int $limitBytes): float
    {
        if ($limitBytes < 1) {
            return 100;
        }

        return min(100, ($usedBytes / $limitBytes) * 100);
    }

    private function percentLabel(float $percent, int $usedBytes): string
    {
        if ($usedBytes < 1) {
            return '0%';
        }

        if ($percent < 0.1) {
            return '< 0.1%';
        }

        return $this->formatPercent($percent) . '%';
    }

    private function progressPercent(float $percent, int $usedBytes): string
    {
        if ($usedBytes < 1) {
            return '0';
        }

        return $this->formatPercent(max(0.25, $percent));
    }

    private function ariaPercent(float $percent, int $usedBytes): string
    {
        if ($usedBytes < 1) {
            return '0';
        }

        return $this->formatPercent(max(0.001, $percent));
    }

    private function formatPercent(float $percent): string
    {
        $formatted = number_format(min(100, $percent), 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function stringAttribute(Model $row, string $key): string
    {
        return $this->stringValue($row->getAttribute($key));
    }

    private function intAttribute(Model $row, string $key): int
    {
        $value = $row->getAttribute($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
