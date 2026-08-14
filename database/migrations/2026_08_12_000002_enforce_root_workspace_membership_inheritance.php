<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    public function up(): void
    {
        DB::table('workspaces')
            ->whereNull('parent_workspace_uid')
            ->update(['inherits_parent_memberships' => true]);

        DB::statement(
            'ALTER TABLE workspaces ADD CONSTRAINT workspaces_root_membership_inheritance_check '
                . 'CHECK (parent_workspace_uid IS NOT NULL OR inherits_parent_memberships)',
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE workspaces DROP CONSTRAINT IF EXISTS workspaces_root_membership_inheritance_check',
        );
    }
};
