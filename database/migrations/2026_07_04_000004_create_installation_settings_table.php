<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('installation_settings', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->string('scope', 32)->default('installation')->unique();
            $table->unsignedBigInteger('max_markdown_bytes');
            $table->unsignedBigInteger('max_html_bytes');
            $table->unsignedBigInteger('artifact_max_bytes');
            $table->unsignedBigInteger('max_workspace_storage_bytes');
            $table->unsignedBigInteger('max_page_storage_bytes');
            $table->unsignedInteger('max_page_versions');
            $table->unsignedInteger('max_tags_per_page');
            $table->ulid('updated_by_user_uid')->nullable();
            $table->timestampsTz();
            $table->boolean('two_factor_required_for_system_admins')->default(true);
            $table->boolean('two_factor_required_for_all_users')->default(false);
            $table->boolean('realtime_enabled')->default(false);

            $table->foreign('updated_by_user_uid')
                ->references('uid')
                ->on('users')
                ->nullOnDelete();
        });

        // Laravel's unsigned* types map to plain signed integers on PostgreSQL;
        // enforce positivity in the database the same way the string enums are
        // enforced (mirrors the min:1 boundary validation).
        DB::statement(
            'ALTER TABLE installation_settings ADD CONSTRAINT installation_settings_limits_check '
                . 'CHECK (max_markdown_bytes >= 1 AND max_html_bytes >= 1 AND artifact_max_bytes >= 1 '
                . 'AND max_workspace_storage_bytes >= 1 AND max_page_storage_bytes >= 1 '
                . 'AND max_page_versions >= 1 AND max_tags_per_page >= 1)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_settings');
    }
};
