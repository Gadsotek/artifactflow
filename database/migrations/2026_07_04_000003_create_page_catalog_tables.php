<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('workspace_uid');
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->ulid('created_by_user_uid');
            $table->timestampsTz();

            $table->foreign('workspace_uid')->references('uid')->on('workspaces')->cascadeOnDelete();
            $table->foreign('created_by_user_uid')->references('uid')->on('users');
            $table->unique(['workspace_uid', 'slug']);
            $table->index('created_by_user_uid');
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('workspace_uid');
            $table->string('name', 80);
            $table->string('slug', 100);
            $table->ulid('created_by_user_uid');
            $table->timestampsTz();

            $table->foreign('workspace_uid')->references('uid')->on('workspaces')->cascadeOnDelete();
            $table->foreign('created_by_user_uid')->references('uid')->on('users');
            $table->unique(['workspace_uid', 'slug']);
            $table->index('created_by_user_uid');
        });

        Schema::create('pages', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('workspace_uid');
            $table->ulid('owner_user_uid')->index();
            $table->ulid('parent_page_uid')->nullable()->index();
            $table->ulid('category_uid')->nullable()->index();
            $table->ulid('current_version_uid')->nullable()->index();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('type')->index();
            $table->string('status')->index();
            $table->timestampsTz();
            $table->string('access_mode')->default('inherited')->index();

            $table->foreign('workspace_uid')->references('uid')->on('workspaces')->cascadeOnDelete();
            $table->foreign('owner_user_uid')->references('uid')->on('users');
            $table->foreign('category_uid')->references('uid')->on('categories')->nullOnDelete();
            $table->unique(['workspace_uid', 'slug']);
            // Serves the default browse sort (visibility filter + updated_at DESC).
            $table->index(['workspace_uid', 'updated_at']);
        });

        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_type_check '
                . "CHECK (type IN ('markdown', 'html_artifact'))",
        );
        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_status_check '
                . "CHECK (status IN ('draft', 'approved', 'deprecated', 'archived'))",
        );
        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_access_mode_check '
                . "CHECK (access_mode IN ('inherited', 'restricted'))",
        );

        DB::statement("ALTER TABLE pages ADD COLUMN search_vector tsvector NOT NULL DEFAULT ''::tsvector");

        Schema::table('pages', function (Blueprint $table): void {
            $table->unsignedBigInteger('preview_access_revision')->default(0);
            $table->foreign('parent_page_uid')->references('uid')->on('pages')->nullOnDelete();
        });

        DB::statement('CREATE INDEX pages_search_vector_gin_idx ON pages USING GIN (search_vector)');

        Schema::create('page_versions', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('page_uid');
            $table->unsignedInteger('version_number');
            $table->string('content_storage_path', 512);
            $table->string('content_hash', 64);
            $table->unsignedBigInteger('byte_size');
            $table->string('scan_status')->default('clean')->index();
            $table->jsonb('scan_findings')->nullable();
            $table->ulid('created_by_user_uid');
            $table->text('extracted_text')->nullable();
            $table->timestampsTz();
            $table->string('source')->default('editor')->index();
            $table->text('source_text')->nullable();

            $table->foreign('page_uid')->references('uid')->on('pages')->cascadeOnDelete();
            $table->foreign('created_by_user_uid')->references('uid')->on('users');
            $table->unique(['page_uid', 'version_number']);
            $table->unique('content_storage_path');
            $table->index('created_by_user_uid');
        });

        // Laravel's unsigned* types map to plain signed integers on PostgreSQL;
        // enforce the invariants in the database as well.
        DB::statement(
            'ALTER TABLE page_versions ADD CONSTRAINT page_versions_number_positive_check '
                . 'CHECK (version_number >= 1)',
        );
        DB::statement(
            'ALTER TABLE page_versions ADD CONSTRAINT page_versions_byte_size_check '
                . 'CHECK (byte_size >= 0)',
        );
        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_preview_access_revision_check '
                . 'CHECK (preview_access_revision >= 0)',
        );

        DB::statement(
            'ALTER TABLE page_versions ADD CONSTRAINT page_versions_scan_status_check '
                . "CHECK (scan_status IN ('clean', 'warnings'))",
        );
        DB::statement(
            'ALTER TABLE page_versions ADD CONSTRAINT page_versions_source_check '
                . "CHECK ((source)::text = ANY ((ARRAY['editor'::character varying, 'upload'::character varying, 'restore'::character varying, 'mcp'::character varying])::text[]))",
        );

        Schema::table('pages', function (Blueprint $table): void {
            $table->foreign('current_version_uid')->references('uid')->on('page_versions')->nullOnDelete();
        });

        Schema::create('page_tag', function (Blueprint $table): void {
            $table->ulid('page_uid');
            $table->ulid('tag_uid');
            $table->timestampsTz();

            $table->foreign('page_uid')->references('uid')->on('pages')->cascadeOnDelete();
            $table->foreign('tag_uid')->references('uid')->on('tags')->cascadeOnDelete();
            $table->primary(['page_uid', 'tag_uid']);
            // Reverse lookup: tag deletes cascade through this index instead of
            // scanning the pivot (tag_uid is a non-leading column in the PK).
            $table->index('tag_uid');
        });

        Schema::create('page_access_grants', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('page_uid');
            $table->string('subject_type')->index();
            $table->ulid('subject_uid');
            $table->string('role')->index();
            $table->ulid('granted_by_user_uid');
            $table->timestampsTz();

            $table->foreign('page_uid')->references('uid')->on('pages')->cascadeOnDelete();
            $table->foreign('granted_by_user_uid')->references('uid')->on('users');
            $table->unique(['page_uid', 'subject_type', 'subject_uid']);
            $table->index('granted_by_user_uid');
        });

        DB::statement(
            'ALTER TABLE page_access_grants ADD CONSTRAINT page_access_grants_subject_type_check '
                . "CHECK (subject_type IN ('user', 'workspace'))",
        );
        DB::statement(
            'ALTER TABLE page_access_grants ADD CONSTRAINT page_access_grants_role_check '
                . "CHECK (role IN ('reader', 'editor', 'admin'))",
        );

        // Composite keys so cross-workspace category/parent relationships are
        // rejected by the database, not only by application checks.
        DB::statement(
            'CREATE UNIQUE INDEX categories_uid_workspace_uid_unique ON categories (uid, workspace_uid)',
        );
        DB::statement(
            'CREATE UNIQUE INDEX pages_uid_workspace_uid_unique ON pages (uid, workspace_uid)',
        );
        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_category_workspace_fk '
                . 'FOREIGN KEY (category_uid, workspace_uid) REFERENCES categories (uid, workspace_uid) '
                . 'DEFERRABLE INITIALLY IMMEDIATE',
        );
        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_parent_workspace_fk '
                . 'FOREIGN KEY (parent_page_uid, workspace_uid) REFERENCES pages (uid, workspace_uid) '
                . 'DEFERRABLE INITIALLY IMMEDIATE',
        );
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropForeign(['current_version_uid']);
        });

        Schema::dropIfExists('page_access_grants');
        Schema::dropIfExists('page_tag');
        Schema::dropIfExists('page_versions');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('categories');
    }
};
