<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class InstallationUsageOverview
{
    public function __construct(
        private InstallationLimitSettings $limits,
        private ByteSizeFormatter $bytes,
    ) {
    }

    public function overview(User $actor): InstallationStorageUsage
    {
        $limitValues = $this->limits->current();
        $usedBytes = (int) PageVersion::query()->sum('byte_size');
        /** @var list<string> $visibleWorkspaceUids */
        $visibleWorkspaceUids = array_values(WorkspaceMembership::query()
            ->where('user_uid', $actor->uid)
            ->get(['workspace_uid'])
            ->map(static fn (WorkspaceMembership $membership): string => $membership->workspace_uid)
            ->all());

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
            ->leftJoin('pages', 'pages.workspace_uid', '=', 'workspaces.uid')
            ->leftJoin('page_versions', 'page_versions.page_uid', '=', 'pages.uid')
            ->select([
                'workspaces.uid',
                'workspaces.name',
                'workspaces.type',
            ])
            ->selectRaw('COALESCE(SUM(page_versions.byte_size), 0) as used_bytes')
            ->selectRaw('COUNT(DISTINCT pages.uid) as page_count')
            ->selectRaw('COUNT(page_versions.uid) as version_count')
            ->whereIn('workspaces.uid', $visibleWorkspaceUids)
            ->groupBy('workspaces.uid', 'workspaces.name', 'workspaces.type')
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
            ->leftJoin('page_versions', 'page_versions.page_uid', '=', 'pages.uid')
            ->select([
                'pages.uid',
                'pages.title',
                'workspaces.name as workspace_name',
            ])
            ->selectRaw('COALESCE(SUM(page_versions.byte_size), 0) as used_bytes')
            ->selectRaw('COUNT(page_versions.uid) as version_count')
            ->whereIn('pages.workspace_uid', $visibleWorkspaceUids)
            ->groupBy('pages.uid', 'pages.title', 'workspaces.name')
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
