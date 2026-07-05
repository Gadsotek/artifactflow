<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Models\Page;
use App\Models\Tag;
use Illuminate\Support\Str;

final readonly class TagSynchronizer
{
    public function __construct(
        private InstallationLimitSettings $limits,
    ) {
    }

    /**
     * @param list<string> $tagNames
     */
    public function sync(Page $page, array $tagNames, string $createdByUserUid): void
    {
        $tagUids = [];

        foreach ($this->uniqueNormalizedNames($tagNames) as $name) {
            $slug = Str::slug($name);
            $tag = Tag::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'created_by_user_uid' => $createdByUserUid,
                ],
            );

            $tagUids[] = $tag->uid;
        }

        $page->tags()->sync($tagUids);
    }

    /**
     * @param list<string> $tagNames
     *
     * @return list<string>
     */
    public function uniqueNormalizedNames(array $tagNames): array
    {
        $names = [];

        foreach ($tagNames as $tagName) {
            $normalizedName = trim(mb_strtolower($tagName));

            if ($normalizedName === '') {
                continue;
            }

            // Str::slug() strips a NUL/control byte, so the searchable-characters guard
            // below would pass a name like "ru\0n" through to the tags text column (500).
            if (!PageContentEncoding::isStorable($normalizedName)) {
                throw new DomainRuleViolation('Tag names must not contain control characters or invalid text.');
            }

            if (mb_strlen($normalizedName) > 80) {
                throw new DomainRuleViolation('Tag names must be 80 characters or fewer.');
            }

            if (Str::slug($normalizedName) === '') {
                throw new DomainRuleViolation('Tag names must contain searchable characters.');
            }

            $names[$normalizedName] = $normalizedName;
        }

        $result = array_values($names);
        $tagLimit = $this->limits->integer('pages.max_tags_per_page');

        if (count($result) > $tagLimit) {
            throw new DomainRuleViolation(sprintf('Pages can have at most %d tags.', $tagLimit));
        }

        return $result;
    }
}
