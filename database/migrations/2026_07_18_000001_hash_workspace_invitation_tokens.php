<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Store only a one-way hash of the emailed invitation link secret, matching
     * how trusted-device and MCP tokens are already held. The plaintext token
     * lives only in the recipient's inbox; a read-only database leak (a backup, a
     * SQL-injection elsewhere) then exposes no usable, still-live invitation link.
     * The raw token stays 48 chars of entropy; its SHA-256 is 64 hex chars.
     */
    public function up(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->string('token_hash', 64)->nullable()->after('invited_email');
        });

        // Preserve in-flight invitations: hash each existing plaintext token so its
        // emailed link keeps resolving. Hashed in PHP to stay driver-agnostic.
        foreach (DB::table('workspace_invitations')->whereNotNull('token')->get(['uid', 'token']) as $row) {
            if (!is_string($row->token) || $row->token === '') {
                continue;
            }

            DB::table('workspace_invitations')
                ->where('uid', $row->uid)
                ->update(['token_hash' => hash('sha256', $row->token)]);
        }

        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });

        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->string('token_hash', 64)->nullable(false)->change();
            $table->unique('token_hash');
        });
    }

    /**
     * Reversal cannot restore the original secrets (they were one-way hashed and
     * never stored). The plaintext column is re-added nullable so the schema
     * shape is recoverable; existing invitations must be reissued after a rollback.
     */
    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->dropUnique(['token_hash']);
            $table->dropColumn('token_hash');
        });

        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->string('token', 64)->nullable()->after('invited_email');
        });
    }
};
