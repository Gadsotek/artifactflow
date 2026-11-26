<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use App\Domain\PageCatalog\PageType;
use App\Models\InstallationSettings;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Throwable;

final readonly class PagePassageIndex
{
    private const int MAX_LOCK_WAIT_MS = 250;

    public function __construct(private PassageExtractor $extractor)
    {
    }

    /** @phpstan-impure */
    public function prepare(PageType $type, string $source): ?PreparedVersionPassages
    {
        if (!in_array($type, [PageType::Markdown, PageType::HtmlArtifact], true)) {
            return null;
        }

        try {
            $prepare = function () use ($type, $source): ?PreparedVersionPassages {
                $generation = $this->activeGeneration();
                if ($generation === null) {
                    return null;
                }

                return new PreparedVersionPassages(
                    $type,
                    $generation,
                    PassageExtractor::REVISION,
                    hash('sha256', $source),
                    $this->extractor->extract($type, $source),
                );
            };

            // MCP can already own a transaction. Isolate errors from every
            // optional query, including the extractor's installation limits.
            return DB::transactionLevel() === 0 ? $prepare() : DB::transaction($prepare);
        } catch (Throwable $error) {
            $this->preserveTransactionErrors($error);
            Log::warning('passage_retrieval.preparation_failed', ['reason' => 'extraction']);

            return null;
        }
    }

    public function publish(string $pageUid, string $versionUid, PreparedVersionPassages $prepared): bool
    {
        try {
            // A nested Laravel transaction is a PostgreSQL savepoint. A bad
            // passage row cannot leave a partial set or abort a valid version.
            return DB::transaction(function () use ($pageUid, $versionUid, $prepared): bool {
                // pg_settings reports lock_timeout in milliseconds. Preserve any
                // stricter operator limit and restore the caller's setting on success.
                // A rollback to the savepoint restores it on an exception.
                $previousTimeout = DB::scalar(
                    "SELECT setting FROM pg_settings WHERE name = 'lock_timeout' AND (setting = '0' OR setting::integer > ?)",
                    [self::MAX_LOCK_WAIT_MS],
                );
                if (!is_string($previousTimeout)) {
                    return $this->replaceCurrentSet($pageUid, $versionUid, $prepared);
                }
                DB::select("SELECT set_config('lock_timeout', ?, true)", [(string) self::MAX_LOCK_WAIT_MS]);
                $published = $this->replaceCurrentSet($pageUid, $versionUid, $prepared);
                DB::select("SELECT set_config('lock_timeout', ?, true)", [$previousTimeout]);

                return $published;
            });
        } catch (Throwable $error) {
            $this->preserveTransactionErrors($error);
            // QueryException includes the bound excerpts. Only record a fixed reason.
            Log::warning('passage_retrieval.publication_failed', ['reason' => 'projection_write']);

            return false;
        }
    }

    public function activeGeneration(bool $lock = false): ?int
    {
        $query = InstallationSettings::query()->where('scope', InstallationSettings::SCOPE_INSTALLATION)
            ->where('passage_retrieval_enabled', true)->where('passage_retrieval_extractor_revision', PassageExtractor::REVISION);
        if ($lock) {
            $query->sharedLock();
        }

        return $query->first(['passage_retrieval_generation'])?->passage_retrieval_generation;
    }

    private function replaceCurrentSet(string $pageUid, string $versionUid, PreparedVersionPassages $prepared): bool
    {
        $page = Page::query()->select(['uid', 'current_version_uid', 'type'])->whereKey($pageUid)->lockForUpdate()->first();
        if ($page === null || $page->current_version_uid !== $versionUid || $page->type !== $prepared->type
            || $prepared->extractorRevision !== PassageExtractor::REVISION
            || $this->activeGeneration(lock: true) !== $prepared->generation) {
            return false;
        }
        $sourceMatches = PageVersion::query()->whereKey($versionUid)->where('page_uid', $pageUid)
            ->where('content_hash', $prepared->sourceHash)->exists();
        if (!$sourceMatches) {
            return false;
        }

        DB::table('passage_sets')->whereIn('page_version_uid', PageVersion::query()->select('uid')
            ->where('page_uid', $pageUid)->where('uid', '<>', $versionUid))->delete();
        DB::table('passage_sets')->where('page_version_uid', $versionUid)
            ->where('generation', $prepared->generation)->where('extractor_revision', $prepared->extractorRevision)->delete();

        $this->insertSet($versionUid, $prepared);
        DB::table('passage_index_failures')->where('page_version_uid', $versionUid)
            ->where('generation', $prepared->generation)->where('extractor_revision', $prepared->extractorRevision)->delete();

        return true;
    }

    private function insertSet(string $versionUid, PreparedVersionPassages $prepared): void
    {
        $setUid = (string) Str::ulid();
        DB::table('passage_sets')->insert([
            'uid' => $setUid,
            'page_version_uid' => $versionUid,
            'generation' => $prepared->generation,
            'extractor_revision' => $prepared->extractorRevision,
            'source_hash' => $prepared->sourceHash,
            'outcome' => $prepared->extraction->outcome->value,
        ]);
        $rows = [];
        foreach ($prepared->extraction->passages as $ordinal => $passage) {
            $rows[] = [
                'uid' => (string) Str::ulid(),
                'passage_set_uid' => $setUid,
                'ordinal' => $ordinal,
                'heading_path' => json_encode($passage->headingPath, JSON_THROW_ON_ERROR),
                'excerpt' => $passage->excerpt,
                'source_locator' => json_encode($passage->sourceLocator, JSON_THROW_ON_ERROR),
            ];
        }
        if ($rows !== []) {
            DB::table('passages')->insert($rows);
        }
    }

    private function preserveTransactionErrors(Throwable $error): void
    {
        if (!$error instanceof PDOException && !$error instanceof LostConnectionException) {
            return;
        }
        // These statement-local errors can be rolled back to the savepoint.
        // A deadlock, lost connection or failed rollback belongs to the core
        // transaction. Its exception must carry no SQL bindings or source.
        if ($error instanceof QueryException) {
            $state = (string) $error->getCode();
            if (in_array(substr($state, 0, 2), ['22', '23', '42', 'P0'], true)
                || in_array($state, ['55P03', '57014'], true)) {
                return;
            }
        }

        throw new RuntimeException('The passage index database transaction was interrupted.');
    }
}
