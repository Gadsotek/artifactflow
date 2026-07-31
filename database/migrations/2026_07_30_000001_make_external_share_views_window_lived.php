<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE external_share_sessions '
                . 'DROP CONSTRAINT IF EXISTS external_share_sessions_shape_check',
        );
        DB::statement(
            'ALTER TABLE external_share_sessions ALTER COLUMN expires_at DROP NOT NULL',
        );
        DB::table('external_share_sessions')
            ->where('kind', 'view')
            ->update(['expires_at' => null]);
        DB::statement(
            'ALTER TABLE external_share_sessions ADD CONSTRAINT external_share_sessions_shape_check '
                . "CHECK ((kind = 'pending_open' AND expires_at IS NOT NULL) "
                . "OR (kind = 'view' AND expires_at IS NULL AND consumed_at IS NULL))",
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE external_share_sessions '
                . 'DROP CONSTRAINT IF EXISTS external_share_sessions_shape_check',
        );
        DB::table('external_share_sessions')
            ->where('kind', 'view')
            ->whereNull('expires_at')
            ->update(['expires_at' => now()]);
        DB::statement(
            'ALTER TABLE external_share_sessions ALTER COLUMN expires_at SET NOT NULL',
        );
        DB::statement(
            'ALTER TABLE external_share_sessions ADD CONSTRAINT external_share_sessions_shape_check '
                . "CHECK (kind = 'pending_open' OR consumed_at IS NULL)",
        );
    }
};
