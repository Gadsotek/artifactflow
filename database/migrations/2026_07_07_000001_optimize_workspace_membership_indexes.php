<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            // Hot-path reverse lookups filter by user_uid alone (search, workspace
            // context, per-page authorization). PostgreSQL does not index foreign-key
            // columns automatically, and the (workspace_uid, user_uid) unique index
            // cannot seek on a user_uid-only predicate.
            $table->index('user_uid');
        });

        Schema::table('workspace_membership_removals', function (Blueprint $table): void {
            // Redundant with the unique (workspace_uid, user_uid) key: there is at most
            // one row per pair, so the wider index served no read it did not already,
            // and only added write cost.
            $table->dropIndex(['workspace_uid', 'user_uid', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_membership_removals', function (Blueprint $table): void {
            $table->index(['workspace_uid', 'user_uid', 'removed_at']);
        });

        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->dropIndex(['user_uid']);
        });
    }
};
