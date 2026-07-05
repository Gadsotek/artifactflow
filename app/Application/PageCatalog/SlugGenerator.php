<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Page;
use App\Models\Workspace;
use Illuminate\Support\Str;

final class SlugGenerator
{
    /**
     * Upper bound for a generated slug. Matches the pages.slug column
     * (varchar(255)); the base slug and any "-<n>" disambiguator are trimmed to
     * fit so that even a maximum-length title can never overflow the column.
     */
    public const int MAX_LENGTH = 255;

    public function uniqueForWorkspace(string $workspaceUid, string $title, ?string $exceptPageUid = null): string
    {
        // Serialize slug assignment within the workspace. Locking the workspace
        // row makes the "does this slug already exist?" check and the caller's
        // insert/update atomic against a concurrent create, move, or rename in
        // the same workspace -- otherwise two transactions can both pass the
        // existence check and then collide on the (workspace_uid, slug) unique
        // index, surfacing as a 500. Every caller runs inside a DB transaction,
        // so the lock is held until their write commits. The create and move
        // paths already hold this exact row lock, so re-locking is a no-op there;
        // acquiring it after the page row (as the rename path does) preserves the
        // page -> workspace lock order used elsewhere in the catalog.
        Workspace::query()->whereKey($workspaceUid)->lockForUpdate()->first();

        $baseSlug = $this->baseSlug($title);
        $slug = $baseSlug;
        $suffix = 2;

        while ($this->slugExists($workspaceUid, $slug, $exceptPageUid)) {
            $slug = $this->withDisambiguator($baseSlug, $suffix);
            $suffix++;
        }

        return $slug;
    }

    private function baseSlug(string $title): string
    {
        $slug = rtrim(mb_substr(Str::slug($title), 0, self::MAX_LENGTH), '-');

        return $slug === '' ? 'page' : $slug;
    }

    private function withDisambiguator(string $baseSlug, int $suffix): string
    {
        $suffixPart = '-' . $suffix;
        // Leave room for the disambiguator, then drop any hyphen the cut exposed
        // so the result never ends up like "foo--2" or with a trailing dash.
        $base = rtrim(mb_substr($baseSlug, 0, self::MAX_LENGTH - mb_strlen($suffixPart)), '-');

        if ($base === '') {
            $base = 'page';
        }

        return $base . $suffixPart;
    }

    private function slugExists(string $workspaceUid, string $slug, ?string $exceptPageUid): bool
    {
        $query = Page::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('slug', $slug);

        if ($exceptPageUid !== null) {
            $query->where('uid', '!=', $exceptPageUid);
        }

        return $query->exists();
    }
}
