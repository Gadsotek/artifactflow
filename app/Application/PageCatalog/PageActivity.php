<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\AuditEntry;
use App\Models\Page;
use App\Models\User;

final class PageActivity
{
    private const int ENTRY_LIMIT = 50;

    /**
     * @return list<PageActivityItem>
     */
    public function forPage(Page $page): array
    {
        // Read the page's activity directly from the retained audit trail. The source
        // event's aggregate reference is denormalized onto audit_entries at write time,
        // so this timeline survives domain-event pruning (the journal is prunable; the
        // audit entries are not).
        $entries = AuditEntry::query()
            ->with('actor')
            ->where('aggregate_type', 'page')
            ->where('aggregate_uid', $page->uid)
            ->orderByDesc('occurred_at')
            ->limit(self::ENTRY_LIMIT)
            ->get();
        $items = [];

        foreach ($entries as $entry) {
            $actor = $entry->actor;
            $items[] = new PageActivityItem(
                summary: $entry->summary,
                actorName: $actor instanceof User ? $actor->name : 'System',
                occurredAt: $entry->occurred_at,
            );
        }

        return $items;
    }
}
