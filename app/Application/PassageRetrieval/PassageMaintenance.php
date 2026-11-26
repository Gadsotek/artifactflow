<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use App\Application\PageCatalog\ArtifactContentReader;
use App\Domain\PageCatalog\PageType;
use App\Jobs\BackfillPassageIndex;
use App\Jobs\PurgePassageGeneration;
use App\Models\InstallationSettings;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PassageMaintenance
{
    private const int BUILD_BATCH = 8;
    private const int PURGE_BATCH = 64;
    private const array TABLES = ['passage_sets', 'passage_index_failures'];

    public function __construct(
        private PagePassageIndex $index,
        private ArtifactContentReader $reader,
    ) {
    }

    public function dispatchPending(): void
    {
        $settings = InstallationSettings::query()->where('scope', InstallationSettings::SCOPE_INSTALLATION)->first();
        if ($settings === null || $settings->passage_retrieval_generation === 0) {
            return;
        }
        $generation = $settings->passage_retrieval_generation;
        $revision = $settings->passage_retrieval_extractor_revision;
        if ($settings->passage_retrieval_enabled && $revision === PassageExtractor::REVISION
            && ($this->pending($generation, $revision)->exists() || $this->hasSuperseded($generation))) {
            BackfillPassageIndex::dispatch($generation, $revision);
        }
        $retired = [];
        foreach (self::TABLES as $table) {
            $oldest = DB::table($table)->where('generation', '<', $generation)->min('generation');
            if (is_numeric($oldest)) {
                $retired[(int) $oldest] = true;
            }
        }
        foreach (array_keys($retired) as $old) {
            PurgePassageGeneration::dispatch($old);
        }
    }

    public function backfill(int $generation, int $revision): void
    {
        if ($revision !== PassageExtractor::REVISION || $this->index->activeGeneration() !== $generation) {
            return;
        }
        foreach (self::TABLES as $table) {
            $this->deleteBatch($table, $this->superseded($table, $generation), $generation);
        }
        $pages = $this->pending($generation, $revision)
            ->with('currentVersion:uid,page_uid,content_storage_path,content_hash')
            ->orderBy('pages.current_version_uid')->limit(self::BUILD_BATCH)->get();
        foreach ($pages as $page) {
            if ($this->index->activeGeneration() !== $generation) {
                return;
            }
            $version = $page->currentVersion;
            if (!$version instanceof PageVersion || $version->page_uid !== $page->uid) {
                continue;
            }
            $source = $this->reader->read($version->content_storage_path);
            $reason = match (true) {
                $source === null => 'source_unavailable',
                hash('sha256', $source) !== $version->content_hash => 'hash_mismatch',
                default => null,
            };
            if ($source !== null && $reason === null) {
                $prepared = $this->index->prepare($page->type, $source);
                if ($prepared !== null && $prepared->generation === $generation
                    && $this->index->publish($page->uid, $version->uid, $prepared)) {
                    continue;
                }
                $reason = 'projection_failed';
            }
            $this->recordFailure($page, $version, $generation, $revision, $reason);
        }
    }

    public function purge(int $generation): void
    {
        // Generations increase monotonically. A queued purge can never target
        // a current or future generation, including after a rapid re-enable.
        if (!InstallationSettings::query()->where('scope', InstallationSettings::SCOPE_INSTALLATION)
            ->where('passage_retrieval_generation', '>', $generation)->exists()) {
            return;
        }
        foreach (self::TABLES as $table) {
            $this->deleteBatch($table, DB::table($table)->where('generation', $generation), $generation);
        }
    }

    /** @return Builder<Page> */
    private function pending(int $generation, int $revision): Builder
    {
        return Page::query()->select(['pages.uid', 'pages.type', 'pages.current_version_uid'])
            ->join('page_versions as v', function (\Illuminate\Database\Query\JoinClause $join): void {
                $join->on('v.uid', '=', 'pages.current_version_uid')->on('v.page_uid', '=', 'pages.uid');
            })
            ->whereIn('pages.type', [PageType::Markdown, PageType::HtmlArtifact])
            ->whereNotExists(DB::table('passage_sets')->selectRaw('1')->whereColumn('page_version_uid', 'v.uid')
                ->where('generation', $generation)->where('extractor_revision', $revision))
            ->whereNotExists(DB::table('passage_index_failures')->selectRaw('1')->whereColumn('page_version_uid', 'v.uid')
                ->where('generation', $generation)->where('extractor_revision', $revision)->where('retry_at', '>', now()));
    }

    private function hasSuperseded(int $generation): bool
    {
        foreach (self::TABLES as $table) {
            if ($this->superseded($table, $generation)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function superseded(string $table, int $generation): QueryBuilder
    {
        return DB::table($table)->where('generation', $generation)
            ->whereNotExists(DB::table('pages')->selectRaw('1')->whereColumn('current_version_uid', $table . '.page_version_uid'));
    }

    private function deleteBatch(string $table, QueryBuilder $candidates, int $generation): void
    {
        $ids = $candidates->orderBy('uid')->limit(self::PURGE_BATCH)->pluck('uid');
        if ($ids->isNotEmpty()) {
            DB::table($table)->where('generation', $generation)->whereIn('uid', $ids)->delete();
        }
    }

    private function recordFailure(Page $page, PageVersion $version, int $generation, int $revision, string $reason): void
    {
        DB::transaction(function () use ($page, $version, $generation, $revision, $reason): void {
            $current = Page::query()->whereKey($page->uid)->lockForUpdate()->first(['current_version_uid']);
            $key = ['page_version_uid' => $version->uid, 'generation' => $generation, 'extractor_revision' => $revision];
            if ($current?->current_version_uid !== $version->uid || $this->index->activeGeneration(lock: true) !== $generation
                || DB::table('passage_sets')->where($key)->exists()) {
                return;
            }
            $existing = DB::table('passage_index_failures')->where($key)->first(['uid', 'attempts']);
            $priorAttempts = $existing->attempts ?? 0;
            $attempts = min(16, (is_numeric($priorAttempts) ? (int) $priorAttempts : 0) + 1);
            DB::table('passage_index_failures')->upsert([$key + [
                'uid' => $existing->uid ?? (string) Str::ulid(),
                'attempts' => $attempts,
                'reason' => $reason,
                'retry_at' => now()->addMinutes(min(60, 2 ** ($attempts - 1))),
            ]], array_keys($key), ['attempts', 'reason', 'retry_at']);
        });
    }
}
