<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class() extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            // Existing installations may have one row per workspace for the same
            // slug. Repoint every pivot to the oldest ULID before removing the
            // workspace boundary, preserving all page associations without
            // violating the composite page_tag primary key.
            DB::statement(<<<'SQL'
                INSERT INTO page_tag (page_uid, tag_uid, created_at, updated_at)
                SELECT page_tag.page_uid, canonical.uid, page_tag.created_at, page_tag.updated_at
                FROM page_tag
                INNER JOIN tags ON tags.uid = page_tag.tag_uid
                INNER JOIN (
                    SELECT slug, MIN(uid) AS uid
                    FROM tags
                    GROUP BY slug
                ) AS canonical ON canonical.slug = tags.slug
                WHERE page_tag.tag_uid <> canonical.uid
                ON CONFLICT (page_uid, tag_uid) DO NOTHING
                SQL);
            DB::statement(<<<'SQL'
                DELETE FROM page_tag
                USING tags, (
                    SELECT slug, MIN(uid) AS uid
                    FROM tags
                    GROUP BY slug
                ) AS canonical
                WHERE page_tag.tag_uid = tags.uid
                  AND canonical.slug = tags.slug
                  AND tags.uid <> canonical.uid
                SQL);
            DB::statement(<<<'SQL'
                DELETE FROM tags
                USING (
                    SELECT slug, MIN(uid) AS uid
                    FROM tags
                    GROUP BY slug
                ) AS canonical
                WHERE canonical.slug = tags.slug
                  AND tags.uid <> canonical.uid
                SQL);

            Schema::table('tags', function (Blueprint $table): void {
                $table->dropUnique('tags_workspace_uid_slug_unique');
                $table->dropForeign('tags_workspace_uid_foreign');
                $table->dropColumn('workspace_uid');
                $table->unique('slug');
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::table('tags', function (Blueprint $table): void {
                $table->dropUnique('tags_slug_unique');
                $table->ulid('workspace_uid')->nullable();
            });

            $tags = DB::table('tags')->orderBy('uid')->get();

            foreach ($tags as $tag) {
                if (!is_string($tag->uid) || !is_string($tag->created_by_user_uid)) {
                    continue;
                }

                $workspaceUids = DB::table('page_tag')
                    ->join('pages', 'pages.uid', '=', 'page_tag.page_uid')
                    ->where('page_tag.tag_uid', $tag->uid)
                    ->distinct()
                    ->orderBy('pages.workspace_uid')
                    ->pluck('pages.workspace_uid')
                    ->filter('is_string')
                    ->values()
                    ->all();

                if ($workspaceUids === []) {
                    $fallbackWorkspaceUid = DB::table('workspace_memberships')
                        ->where('user_uid', $tag->created_by_user_uid)
                        ->orderBy('workspace_uid')
                        ->value('workspace_uid')
                        ?? DB::table('workspaces')->orderBy('uid')->value('uid');

                    if (!is_string($fallbackWorkspaceUid)) {
                        DB::table('tags')->where('uid', $tag->uid)->delete();

                        continue;
                    }

                    $workspaceUids = [$fallbackWorkspaceUid];
                }

                $firstWorkspaceUid = array_shift($workspaceUids);

                if (!is_string($firstWorkspaceUid)) {
                    continue;
                }

                DB::table('tags')->where('uid', $tag->uid)->update([
                    'workspace_uid' => $firstWorkspaceUid,
                ]);

                foreach ($workspaceUids as $workspaceUid) {
                    if (!is_string($workspaceUid)) {
                        continue;
                    }

                    $newTagUid = Str::ulid()->toBase32();
                    DB::table('tags')->insert([
                        'uid' => $newTagUid,
                        'workspace_uid' => $workspaceUid,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                        'created_by_user_uid' => $tag->created_by_user_uid,
                        'created_at' => $tag->created_at,
                        'updated_at' => $tag->updated_at,
                    ]);

                    $pageUids = DB::table('pages')
                        ->where('workspace_uid', $workspaceUid)
                        ->pluck('uid')
                        ->filter('is_string')
                        ->all();

                    if ($pageUids !== []) {
                        DB::table('page_tag')
                            ->where('tag_uid', $tag->uid)
                            ->whereIn('page_uid', $pageUids)
                            ->update(['tag_uid' => $newTagUid]);
                    }
                }
            }

            DB::statement('ALTER TABLE tags ALTER COLUMN workspace_uid SET NOT NULL');

            Schema::table('tags', function (Blueprint $table): void {
                $table->foreign('workspace_uid')->references('uid')->on('workspaces')->cascadeOnDelete();
                $table->unique(['workspace_uid', 'slug']);
            });
        });
    }
};
