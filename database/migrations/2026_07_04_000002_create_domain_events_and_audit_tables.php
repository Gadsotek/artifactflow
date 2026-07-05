<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->string('event_type', 120)->index();
            $table->string('aggregate_type', 80);
            $table->ulid('aggregate_uid');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at')->index();
            $table->timestampTz('dispatched_at')->nullable()->index();
            $table->timestampsTz();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestampTz('failed_at')->nullable()->index();
            $table->string('last_error', 180)->nullable();

            $table->index(['aggregate_type', 'aggregate_uid']);
        });

        DB::statement(
            'ALTER TABLE domain_events ADD CONSTRAINT domain_events_dispatch_attempts_check '
                . 'CHECK (dispatch_attempts >= 0)',
        );

        // Serves the outbox dispatcher's pending-event scan directly: it orders
        // by (occurred_at, uid) over only the not-yet-dispatched, not-failed rows.
        DB::statement(
            'CREATE INDEX domain_events_pending_dispatch_idx ON domain_events (occurred_at, uid) '
                . 'WHERE dispatched_at IS NULL AND failed_at IS NULL',
        );

        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            // Soft reference into the domain-event journal, deliberately without
            // a foreign key: the journal is prunable after dispatch (see
            // artifactflow:prune-domain-events) while audit entries are retained,
            // and the recorded uid stays meaningful as provenance either way.
            $table->ulid('event_uid')->index();
            $table->ulid('actor_user_uid')->nullable()->index();
            $table->string('auditable_type', 80);
            $table->ulid('auditable_uid');
            $table->string('action', 120)->index();
            $table->string('summary', 255);
            $table->jsonb('metadata');
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();

            $table->foreign('actor_user_uid')->references('uid')->on('users');
            $table->index(['auditable_type', 'auditable_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
        Schema::dropIfExists('domain_events');
    }
};
