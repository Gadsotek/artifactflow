<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->boolean('external_sharing_enabled')->default(false);
            $table->boolean('external_share_acknowledgement_required')->default(true);
            $table->unsignedInteger('external_share_max_expiry_hours')->default(168);
        });

        DB::statement(
            'ALTER TABLE installation_settings ADD CONSTRAINT installation_settings_external_share_expiry_check '
                . 'CHECK (external_share_max_expiry_hours BETWEEN 1 AND 720)',
        );

        Schema::create('external_shares', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('page_uid');
            $table->char('secret_hash', 64)->unique();
            $table->string('mode', 24);
            $table->timestampTz('expires_at', 6)->nullable();
            $table->ulid('page_workspace_uid');
            $table->unsignedBigInteger('page_access_revision');
            $table->ulid('created_by_user_uid');
            $table->timestampTz('redeemed_at', 6)->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->ulid('revoked_by_user_uid')->nullable();
            $table->timestampTz('first_viewed_at', 6)->nullable();
            $table->timestampTz('last_viewed_at', 6)->nullable();
            $table->unsignedInteger('view_session_count')->default(0);
            $table->timestampsTz();

            $table->foreign('page_uid')->references('uid')->on('pages')->cascadeOnDelete();
            $table->foreign('page_workspace_uid')->references('uid')->on('workspaces');
            $table->foreign('created_by_user_uid')->references('uid')->on('users');
            $table->foreign('revoked_by_user_uid')->references('uid')->on('users');
            $table->index(['page_uid', 'created_at']);
            $table->index(['page_uid', 'revoked_at', 'redeemed_at']);
            $table->index(['mode', 'expires_at']);
        });

        DB::statement(
            'ALTER TABLE external_shares ADD CONSTRAINT external_shares_mode_check '
                . "CHECK (mode IN ('expires_at', 'one_time'))",
        );
        DB::statement(
            'ALTER TABLE external_shares ADD CONSTRAINT external_shares_shape_check CHECK ('
                . "(mode = 'expires_at' AND expires_at IS NOT NULL AND redeemed_at IS NULL "
                . "OR mode = 'one_time' AND expires_at IS NULL) "
                . 'AND (revoked_at IS NULL) = (revoked_by_user_uid IS NULL))',
        );
        DB::statement(
            'ALTER TABLE external_shares ADD CONSTRAINT external_shares_secret_hash_check '
                . "CHECK (secret_hash ~ '^[0-9a-f]{64}$')",
        );
        DB::statement(
            'ALTER TABLE external_shares ADD CONSTRAINT external_shares_access_revision_check '
                . 'CHECK (page_access_revision >= 0)',
        );
        DB::statement(
            'ALTER TABLE external_shares ADD CONSTRAINT external_shares_view_session_count_check '
                . 'CHECK (view_session_count >= 0)',
        );

        Schema::create('external_share_sessions', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('external_share_uid');
            $table->char('credential_hash', 64)->unique();
            $table->string('kind', 24);
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->timestampsTz();

            $table->foreign('external_share_uid')
                ->references('uid')
                ->on('external_shares')
                ->cascadeOnDelete();
            $table->index(['external_share_uid', 'kind', 'expires_at']);
        });

        DB::statement(
            'ALTER TABLE external_share_sessions ADD CONSTRAINT external_share_sessions_kind_check '
                . "CHECK (kind IN ('pending_open', 'view'))",
        );
        DB::statement(
            'ALTER TABLE external_share_sessions ADD CONSTRAINT external_share_sessions_credential_hash_check '
                . "CHECK (credential_hash ~ '^[0-9a-f]{64}$')",
        );
        DB::statement(
            'ALTER TABLE external_share_sessions ADD CONSTRAINT external_share_sessions_shape_check '
                . "CHECK ((kind = 'pending_open' AND expires_at IS NOT NULL) "
                . "OR (kind = 'view' AND expires_at IS NULL AND consumed_at IS NULL))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('external_share_sessions');
        Schema::dropIfExists('external_shares');

        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'external_sharing_enabled',
                'external_share_acknowledgement_required',
                'external_share_max_expiry_hours',
            ]);
        });
    }
};
