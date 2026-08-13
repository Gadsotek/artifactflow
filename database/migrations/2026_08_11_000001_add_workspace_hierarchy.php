<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->ulid('parent_workspace_uid')->nullable();
            $table->foreign('parent_workspace_uid')
                ->references('uid')
                ->on('workspaces')
                ->restrictOnDelete();
            $table->index('parent_workspace_uid');
        });

        DB::statement(
            'ALTER TABLE workspaces ADD CONSTRAINT workspaces_hierarchy_shape_check '
                . "CHECK ((type <> 'personal' OR parent_workspace_uid IS NULL) "
                . 'AND (parent_workspace_uid IS NULL OR parent_workspace_uid <> uid))',
        );

        Schema::create('workspace_ancestry', function (Blueprint $table): void {
            $table->ulid('ancestor_workspace_uid');
            $table->ulid('descendant_workspace_uid');
            $table->unsignedSmallInteger('depth');

            $table->primary(
                ['ancestor_workspace_uid', 'descendant_workspace_uid'],
                'workspace_ancestry_primary',
            );
            $table->index(
                ['descendant_workspace_uid', 'depth', 'ancestor_workspace_uid'],
                'workspace_ancestry_descendant_depth_index',
            );
            $table->index(
                ['ancestor_workspace_uid', 'depth', 'descendant_workspace_uid'],
                'workspace_ancestry_ancestor_depth_index',
            );

            $table->foreign('ancestor_workspace_uid')
                ->references('uid')
                ->on('workspaces')
                ->cascadeOnDelete();
            $table->foreign('descendant_workspace_uid')
                ->references('uid')
                ->on('workspaces')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE workspace_ancestry ADD CONSTRAINT workspace_ancestry_depth_check '
                . 'CHECK (depth BETWEEN 0 AND 2)',
        );
        DB::statement(
            'ALTER TABLE workspace_ancestry ADD CONSTRAINT workspace_ancestry_self_depth_check '
                . 'CHECK ((depth = 0 AND ancestor_workspace_uid = descendant_workspace_uid) '
                . 'OR (depth > 0 AND ancestor_workspace_uid <> descendant_workspace_uid))',
        );

        DB::statement(
            'INSERT INTO workspace_ancestry '
                . '(ancestor_workspace_uid, descendant_workspace_uid, depth) '
                . 'SELECT uid, uid, 0 FROM workspaces',
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION artifactflow_insert_workspace_self_ancestry()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                INSERT INTO workspace_ancestry (
                    ancestor_workspace_uid,
                    descendant_workspace_uid,
                    depth
                ) VALUES (NEW.uid, NEW.uid, 0);

                RETURN NEW;
            END;
            $$
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER workspaces_insert_self_ancestry
            AFTER INSERT ON workspaces
            FOR EACH ROW
            EXECUTE FUNCTION artifactflow_insert_workspace_self_ancestry()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS workspaces_insert_self_ancestry ON workspaces');
        DB::statement('DROP FUNCTION IF EXISTS artifactflow_insert_workspace_self_ancestry()');

        Schema::dropIfExists('workspace_ancestry');

        DB::statement('ALTER TABLE workspaces DROP CONSTRAINT IF EXISTS workspaces_hierarchy_shape_check');

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropForeign(['parent_workspace_uid']);
            $table->dropIndex(['parent_workspace_uid']);
            $table->dropColumn('parent_workspace_uid');
        });
    }
};
