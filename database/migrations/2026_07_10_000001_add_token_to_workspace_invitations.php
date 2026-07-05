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
        // A high-entropy secret used in the emailed invitation link. The ULID
        // primary key is not a secret (it is time-ordered and surfaces in logs),
        // so it must not double as the bearer for a link that can complete
        // registration. Added nullable first so existing rows can be backfilled
        // with distinct tokens before the NOT NULL + UNIQUE constraints land.
        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->string('token', 64)->nullable()->after('invited_email');
        });

        foreach (DB::table('workspace_invitations')->whereNull('token')->pluck('uid') as $uid) {
            DB::table('workspace_invitations')
                ->where('uid', $uid)
                ->update(['token' => Str::random(48)]);
        }

        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->string('token', 64)->nullable(false)->change();
            $table->unique('token');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });
    }
};
