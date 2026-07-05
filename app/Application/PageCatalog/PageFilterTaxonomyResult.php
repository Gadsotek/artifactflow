<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Category;
use App\Models\Tag;

final readonly class PageFilterTaxonomyResult
{
    /**
     * @param list<Category> $categories
     * @param list<Tag> $tags
     */
    public function __construct(
        public array $categories,
        public array $tags,
    ) {
    }
}
