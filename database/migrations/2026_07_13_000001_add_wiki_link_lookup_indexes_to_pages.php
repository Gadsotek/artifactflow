<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    public function up(): void
    {
        // MarkdownWikiLinkResolver resolves every [[target]] on a page render with
        //   WHERE workspace_uid = ? AND (LOWER(title) IN (...) OR LOWER(slug) IN (...))
        // The unique (workspace_uid, slug) key indexes the raw slug, so the LOWER()
        // wrapper defeats it and both branches fell back to a workspace-scoped scan on
        // every render of a page that links out. Two composite expression indexes let
        // PostgreSQL seek each side of the OR (BitmapOr) within the workspace instead.
        DB::statement(
            'CREATE INDEX pages_workspace_lower_title_index ON pages (workspace_uid, LOWER(title))',
        );
        DB::statement(
            'CREATE INDEX pages_workspace_lower_slug_index ON pages (workspace_uid, LOWER(slug))',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pages_workspace_lower_title_index');
        DB::statement('DROP INDEX IF EXISTS pages_workspace_lower_slug_index');
    }
};
