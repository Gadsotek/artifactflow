<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Models\Page;

final class PageFinder
{
    public static function requireByUid(string $pageUid): Page
    {
        $page = Page::query()->find($pageUid);

        if (!$page instanceof Page) {
            throw new DomainRuleViolation('Page does not exist.');
        }

        return $page;
    }

    /**
     * Load the page under a FOR UPDATE row lock. Callers use this to re-read a page
     * inside a transaction so a subsequent re-authorization or invariant check runs
     * against the committed state, immune to a change that landed after the pre-lock
     * read.
     */
    public static function requireLockedByUid(string $pageUid): Page
    {
        $page = Page::query()->whereKey($pageUid)->lockForUpdate()->first();

        if (!$page instanceof Page) {
            throw new DomainRuleViolation('Page does not exist.');
        }

        return $page;
    }
}
