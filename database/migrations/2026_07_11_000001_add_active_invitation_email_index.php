<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    public function up(): void
    {
        // Every dashboard load looks up a user's pending invitations by invited_email
        // among active (not accepted, not revoked) rows -- see WorkspaceInvitationOverview.
        // The only email-bearing index is the unique (workspace_uid, invited_email), which
        // leads with workspace_uid and cannot seek an email-only predicate, so the lookup
        // seq-scanned all invitations and worsened as revoked/expired rows accumulated. A
        // partial index leading with invited_email and covering only the active rows keeps
        // it an index seek over a small, self-pruning set.
        DB::statement(
            'CREATE INDEX workspace_invitations_active_email_index '
            . 'ON workspace_invitations (invited_email) '
            . 'WHERE accepted_at IS NULL AND revoked_at IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS workspace_invitations_active_email_index');
    }
};
