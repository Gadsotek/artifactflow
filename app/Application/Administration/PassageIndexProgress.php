<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Domain\PageCatalog\PageType;
use App\Models\InstallationSettings;
use Illuminate\Support\Facades\DB;

final readonly class PassageIndexProgress
{
    private function __construct(
        public bool $enabled,
        public int $generation,
        public int $eligible,
        public int $indexed,
        public int $limited,
        public int $failed,
        public int $retired,
    ) {
    }

    public static function current(): self
    {
        $settings = InstallationSettings::query()->where('scope', InstallationSettings::SCOPE_INSTALLATION)->first();
        $generation = $settings->passage_retrieval_generation ?? 0;
        if ($settings === null || $generation === 0) {
            return new self(false, 0, 0, 0, 0, 0, 0);
        }

        $retired = DB::table('passage_sets')->where('generation', '<', $generation)->count()
            + DB::table('passage_index_failures')->where('generation', '<', $generation)->count();
        if (!$settings->passage_retrieval_enabled) {
            return new self(false, $generation, 0, 0, 0, 0, $retired);
        }

        $versions = DB::table('pages')->join('page_versions as v', 'v.uid', '=', 'pages.current_version_uid')
            ->whereColumn('v.page_uid', 'pages.uid')->whereIn('pages.type', [PageType::Markdown, PageType::HtmlArtifact])
            ->select('v.uid');
        $key = ['generation' => $generation, 'extractor_revision' => $settings->passage_retrieval_extractor_revision];
        $sets = DB::table('passage_sets')->where($key)->whereIn('page_version_uid', $versions);
        $failures = DB::table('passage_index_failures')->where($key)->whereIn('page_version_uid', $versions);

        return new self(true, $generation, $versions->count(), $sets->count(), $sets->where('outcome', 'limited')->count(), $failures->count(), $retired);
    }
}
