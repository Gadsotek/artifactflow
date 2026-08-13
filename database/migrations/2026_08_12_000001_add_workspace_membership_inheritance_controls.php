<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('inherits_parent_memberships')->default(true);
        });

        Schema::create('workspace_membership_exclusions', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('workspace_uid');
            $table->ulid('user_uid');
            $table->ulid('excluded_by_user_uid')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_uid', 'user_uid']);
            $table->index(['user_uid', 'workspace_uid']);

            $table->foreign('workspace_uid')
                ->references('uid')
                ->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign('user_uid')
                ->references('uid')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('excluded_by_user_uid')
                ->references('uid')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_membership_exclusions');

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('inherits_parent_memberships');
        });
    }
};
