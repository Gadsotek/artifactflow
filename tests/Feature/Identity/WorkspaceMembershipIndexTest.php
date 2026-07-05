<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class WorkspaceMembershipIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_uid_has_a_dedicated_index_for_reverse_lookups(): void
    {
        $this->assertTrue(
            $this->hasIndexOnColumns('workspace_memberships', ['user_uid']),
            'workspace_memberships.user_uid should carry a dedicated index for user-only lookups.',
        );
    }

    public function test_redundant_removals_index_is_dropped_but_unique_key_remains(): void
    {
        $this->assertFalse(
            $this->hasIndexOnColumns('workspace_membership_removals', ['workspace_uid', 'user_uid', 'removed_at']),
            'The (workspace_uid, user_uid, removed_at) index is redundant and should be dropped.',
        );

        $this->assertTrue(
            $this->hasIndexOnColumns('workspace_membership_removals', ['workspace_uid', 'user_uid'], unique: true),
            'The unique (workspace_uid, user_uid) key must remain.',
        );
    }

    /**
     * @param list<string> $columns
     */
    private function hasIndexOnColumns(string $table, array $columns, ?bool $unique = null): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (!is_array($index)) {
                continue;
            }

            if (($index['columns'] ?? null) !== $columns) {
                continue;
            }

            if ($unique !== null && ($index['unique'] ?? null) !== $unique) {
                continue;
            }

            return true;
        }

        return false;
    }
}
