<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            // Denormalized copy of the source event's aggregate reference so per-aggregate
            // activity (e.g. a page's timeline) can be read directly from the retained
            // audit trail instead of joining the prunable domain_events journal.
            $table->string('aggregate_type', 80)->nullable();
            $table->ulid('aggregate_uid')->nullable();
            $table->index(['aggregate_type', 'aggregate_uid', 'occurred_at']);
        });

        // Backfill from any events still present in the journal (rows whose event was
        // already pruned stay null; nothing to recover, and new writes populate both).
        DB::statement(
            'UPDATE audit_entries AS a '
                . 'SET aggregate_type = e.aggregate_type, aggregate_uid = e.aggregate_uid '
                . 'FROM domain_events AS e '
                . 'WHERE a.event_uid = e.uid',
        );
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            $table->dropIndex(['aggregate_type', 'aggregate_uid', 'occurred_at']);
            $table->dropColumn(['aggregate_type', 'aggregate_uid']);
        });
    }
};
