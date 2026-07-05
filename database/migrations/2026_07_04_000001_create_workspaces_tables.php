<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->string('name', 160);
            $table->string('type')->index();
            $table->ulid('personal_owner_uid')->nullable()->unique();
            $table->ulid('created_by_user_uid')->nullable();
            $table->timestampsTz();
            $table->boolean('allow_editor_invites')->default(false);
            $table->boolean('allow_editor_page_sharing')->default(true);
            // Maintained counter of page_versions bytes stored in the workspace.
            // Updated inside the same transactions (under the workspace row lock)
            // that create, delete, or move page versions; reconciled by
            // artifactflow:recount-storage.
            $table->unsignedBigInteger('used_storage_bytes')->default(0);

            $table->foreign('personal_owner_uid')->references('uid')->on('users')->cascadeOnDelete();
            $table->foreign('created_by_user_uid')->references('uid')->on('users')->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE workspaces ADD CONSTRAINT workspaces_type_check '
                . "CHECK (type IN ('personal', 'shared'))",
        );

        // Laravel's unsigned* types map to plain signed integers on PostgreSQL;
        // enforce the non-negative invariant in the database as well.
        DB::statement(
            'ALTER TABLE workspaces ADD CONSTRAINT workspaces_used_storage_bytes_check '
                . 'CHECK (used_storage_bytes >= 0)',
        );

        Schema::create('workspace_memberships', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('workspace_uid');
            $table->ulid('user_uid');
            $table->string('role')->index();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();

            $table->foreign('workspace_uid')->references('uid')->on('workspaces')->cascadeOnDelete();
            $table->foreign('user_uid')->references('uid')->on('users')->cascadeOnDelete();
            $table->unique(['workspace_uid', 'user_uid']);
        });

        DB::statement(
            'ALTER TABLE workspace_memberships ADD CONSTRAINT workspace_memberships_role_check '
                . "CHECK (role IN ('reader', 'editor', 'admin'))",
        );

        Schema::create('workspace_invitations', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('workspace_uid');
            $table->string('invited_email', 254);
            $table->string('role')->index();
            $table->ulid('invited_by_user_uid');
            $table->ulid('accepted_by_user_uid')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->foreign('workspace_uid')->references('uid')->on('workspaces')->cascadeOnDelete();
            $table->foreign('invited_by_user_uid')->references('uid')->on('users');
            $table->foreign('accepted_by_user_uid')->references('uid')->on('users');
            $table->unique(['workspace_uid', 'invited_email']);
            $table->index('invited_by_user_uid');
            $table->index('accepted_by_user_uid');
        });

        DB::statement(
            'ALTER TABLE workspace_invitations ADD CONSTRAINT workspace_invitations_role_check '
                . "CHECK (role IN ('reader', 'editor', 'admin'))",
        );

        // Read model recording when a user was last removed from a workspace, so
        // page-access authorization can reject grants that predate a removal
        // without reading the domain-event outbox (which may be pruned).
        Schema::create('workspace_membership_removals', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('workspace_uid');
            $table->ulid('user_uid');
            $table->timestampTz('removed_at');
            $table->timestampsTz();

            $table->unique(['workspace_uid', 'user_uid']);
            $table->index(['workspace_uid', 'user_uid', 'removed_at']);

            $table->foreign('workspace_uid')
                ->references('uid')
                ->on('workspaces')
                ->cascadeOnDelete();

            $table->foreign('user_uid')
                ->references('uid')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_membership_removals');
        Schema::dropIfExists('workspace_invitations');
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('workspaces');
    }
};
