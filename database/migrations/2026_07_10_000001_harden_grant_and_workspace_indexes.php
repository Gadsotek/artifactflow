<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('page_access_grants', function (Blueprint $table): void {
            // Subject-driven scans filter (subject_type, subject_uid) with no page_uid
            // predicate -- PageAccessRevision::bumpPagesGrantedToWorkspace and both
            // RemoveWorkspaceMember grant lookups (the last under lockForUpdate). The
            // unique (page_uid, subject_type, subject_uid) key leads with page_uid and
            // cannot seek them, so they fell back to the low-selectivity subject_type
            // index or a full scan while holding write locks. A composite index that
            // leads with subject_type turns them into index seeks.
            $table->index(['subject_type', 'subject_uid']);

            // The standalone subject_type index is now redundant: the composite above
            // seeks any subject_type-only predicate on its leading column, and the only
            // ordering by subject_type alone (PageDetailAccessOptions) happens within a
            // page_uid scope already covered by the unique key.
            $table->dropIndex(['subject_type']);
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            // created_by_user_uid is a foreign key (nullOnDelete). PostgreSQL does not
            // index foreign-key columns automatically, and the sibling categories /
            // tags / page_versions creator columns are all indexed. Restore parity so
            // a future user-erasure path does not seq-scan workspaces to null this
            // column.
            $table->index('created_by_user_uid');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropIndex(['created_by_user_uid']);
        });

        Schema::table('page_access_grants', function (Blueprint $table): void {
            $table->index('subject_type');
            $table->dropIndex(['subject_type', 'subject_uid']);
        });
    }
};
