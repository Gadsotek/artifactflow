<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SchemaHardeningIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_access_grants_can_seek_grants_by_subject(): void
    {
        // Subject-driven scans (PageAccessRevision::bumpPagesGrantedToWorkspace and
        // RemoveWorkspaceMember's grant lookups) filter (subject_type, subject_uid)
        // with no page_uid predicate, so they need a composite index leading with
        // subject_type -- the unique (page_uid, ...) key cannot seek them.
        $this->assertTrue(
            $this->hasIndexOnColumns('page_access_grants', ['subject_type', 'subject_uid']),
            'page_access_grants should carry a (subject_type, subject_uid) index for subject-driven grant scans.',
        );
    }

    public function test_redundant_subject_type_index_is_dropped_but_unique_key_remains(): void
    {
        $this->assertFalse(
            $this->hasIndexOnColumns('page_access_grants', ['subject_type']),
            'The standalone subject_type index is redundant with the composite index and should be dropped.',
        );

        $this->assertTrue(
            $this->hasIndexOnColumns('page_access_grants', ['page_uid', 'subject_type', 'subject_uid'], unique: true),
            'The unique (page_uid, subject_type, subject_uid) key must remain.',
        );
    }

    public function test_workspaces_creator_foreign_key_is_indexed(): void
    {
        $this->assertTrue(
            $this->hasIndexOnColumns('workspaces', ['created_by_user_uid']),
            'workspaces.created_by_user_uid is a foreign key and should be indexed like its sibling creator columns.',
        );
    }

    public function test_active_invitations_are_seekable_by_invited_email(): void
    {
        // Every dashboard load looks up pending invitations by invited_email among active
        // rows (WorkspaceInvitationOverview). The unique (workspace_uid, invited_email)
        // key leads with workspace_uid and cannot seek an email-only predicate, so a
        // partial index leading with invited_email over the active rows is required.
        $definition = DB::table('pg_indexes')
            ->where('tablename', 'workspace_invitations')
            ->where('indexname', 'workspace_invitations_active_email_index')
            ->value('indexdef');

        $this->assertIsString($definition, 'A partial invited_email index must exist for the pending-invitation lookup.');
        $this->assertStringContainsStringIgnoringCase('invited_email', $definition);
        $this->assertStringContainsStringIgnoringCase('where', $definition);
        $this->assertStringContainsStringIgnoringCase('revoked_at is null', $definition);
        $this->assertStringContainsStringIgnoringCase('accepted_at is null', $definition);
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
