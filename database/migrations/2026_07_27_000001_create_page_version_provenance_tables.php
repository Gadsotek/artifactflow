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
        Schema::create('mcp_client_sessions', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->string('session_id_hash', 64)->unique();
            $table->ulid('mcp_access_token_uid');
            $table->string('client_reported_name', 191)->nullable();
            $table->string('client_reported_version', 120)->nullable();
            $table->string('protocol_version', 32)->nullable();
            $table->timestampTz('initialized_at');
            $table->timestampsTz();

            $table->foreign('mcp_access_token_uid')
                ->references('uid')
                ->on('mcp_access_tokens')
                ->cascadeOnDelete();
            $table->index(['mcp_access_token_uid', 'initialized_at']);
        });

        Schema::create('page_version_ingests', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('page_uid');
            // Deliberate soft reference: provenance survives page-version retention pruning.
            $table->ulid('page_version_uid')->unique();
            // Resolved root for constant-cost reads; also survives version pruning.
            $table->ulid('content_origin_version_uid')->index();
            $table->unsignedInteger('version_number');
            $table->string('content_hash', 64);
            $table->string('operation', 32);
            $table->string('ingest_method', 32);
            $table->ulid('actor_user_uid');
            // Deliberate soft reference: retired MCP tokens are credential-retention pruned.
            $table->ulid('mcp_access_token_uid')->nullable()->index();
            $table->string('mcp_transport_session_id', 120)->nullable();
            $table->string('mcp_client_reported_name', 191)->nullable();
            $table->string('mcp_client_reported_version', 120)->nullable();
            $table->boolean('provenance_supplied_at_ingest')->default(false);
            $table->ulid('derived_from_version_uid')->nullable()->index();
            $table->ulid('content_equivalent_to_version_uid')->nullable()->index();
            $table->timestampTz('recorded_at');
            $table->timestampsTz();

            $table->foreign('page_uid')->references('uid')->on('pages')->cascadeOnDelete();
            $table->foreign('actor_user_uid')->references('uid')->on('users');
            $table->unique(['page_uid', 'version_number']);
            $table->index(['page_uid', 'recorded_at']);
        });

        DB::statement(
            'ALTER TABLE page_version_ingests ADD CONSTRAINT page_version_ingests_number_positive_check '
                . 'CHECK (version_number >= 1)',
        );
        DB::statement(
            'ALTER TABLE page_version_ingests ADD CONSTRAINT page_version_ingests_operation_check '
                . "CHECK (operation IN ('create', 'update', 'restore'))",
        );
        DB::statement(
            'ALTER TABLE page_version_ingests ADD CONSTRAINT page_version_ingests_method_check '
                . "CHECK (ingest_method IN ('editor', 'upload', 'restore', 'mcp'))",
        );
        DB::statement(
            'ALTER TABLE page_version_ingests ADD CONSTRAINT page_version_ingests_hash_check '
                . "CHECK (content_hash ~ '^[0-9a-f]{64}$')",
        );

        // Existing installations already have authoritative page-version facts.
        // Backfill only what ArtifactFlow can observe without inventing producer claims.
        DB::table('page_versions')
            ->select([
                'uid',
                'page_uid',
                'version_number',
                'content_hash',
                'source',
                'created_by_user_uid',
                'created_at',
            ])
            ->orderBy('uid')
            ->chunk(500, static function ($versions): void {
                $rows = [];

                foreach ($versions as $version) {
                    $versionNumberValue = $version->version_number;

                    if (is_int($versionNumberValue)) {
                        $versionNumber = $versionNumberValue;
                    } elseif (is_string($versionNumberValue) && ctype_digit($versionNumberValue)) {
                        $versionNumber = (int) $versionNumberValue;
                    } else {
                        throw new RuntimeException('Existing page version has an invalid version number.');
                    }

                    $operation = $versionNumber === 1
                        ? 'create'
                        : ($version->source === 'restore' ? 'restore' : 'update');
                    $rows[] = [
                        'uid' => (string) Str::ulid(),
                        'page_uid' => $version->page_uid,
                        'page_version_uid' => $version->uid,
                        'content_origin_version_uid' => $version->uid,
                        'version_number' => $versionNumber,
                        'content_hash' => $version->content_hash,
                        'operation' => $operation,
                        'ingest_method' => $version->source,
                        'actor_user_uid' => $version->created_by_user_uid,
                        'mcp_access_token_uid' => null,
                        'mcp_transport_session_id' => null,
                        'mcp_client_reported_name' => null,
                        'mcp_client_reported_version' => null,
                        'provenance_supplied_at_ingest' => false,
                        'derived_from_version_uid' => null,
                        'content_equivalent_to_version_uid' => null,
                        'recorded_at' => $version->created_at,
                        'created_at' => $version->created_at,
                        'updated_at' => $version->created_at,
                    ];
                }

                if ($rows !== []) {
                    DB::table('page_version_ingests')->insert($rows);
                }
            });

        Schema::create('producer_assertions', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('page_version_ingest_uid');
            $table->string('producer_kind', 32);
            $table->string('producer_name', 191)->nullable();
            $table->string('producer_version', 120)->nullable();
            $table->string('provider_key', 80)->nullable();
            $table->string('model_id', 191)->nullable();
            $table->string('model_label', 191)->nullable();
            $table->string('model_version', 120)->nullable();
            $table->timestampTz('generated_at', 6)->nullable();
            $table->string('evidence_type', 32);
            $table->ulid('asserted_by_user_uid');
            $table->ulid('supersedes_assertion_uid')->nullable()->unique();
            $table->timestampTz('asserted_at');
            $table->timestampsTz();

            $table->foreign('page_version_ingest_uid')
                ->references('uid')
                ->on('page_version_ingests')
                ->cascadeOnDelete();
            $table->foreign('asserted_by_user_uid')->references('uid')->on('users');
            $table->index(['page_version_ingest_uid', 'producer_kind']);
            $table->index(['provider_key', 'model_id']);
            $table->index('model_label');
        });

        Schema::table('producer_assertions', function (Blueprint $table): void {
            // PostgreSQL requires the self-referenced primary key to exist before
            // it can validate this foreign key.
            $table->foreign('supersedes_assertion_uid')
                ->references('uid')
                ->on('producer_assertions');
        });

        DB::statement(
            'ALTER TABLE producer_assertions ADD CONSTRAINT producer_assertions_kind_check '
                . "CHECK (producer_kind IN ('ai', 'human', 'software'))",
        );
        DB::statement(
            'ALTER TABLE producer_assertions ADD CONSTRAINT producer_assertions_evidence_check '
                . "CHECK (evidence_type IN ('self_reported', 'client_asserted', 'bridge_asserted', 'provider_attested'))",
        );
        DB::statement(
            'ALTER TABLE producer_assertions ADD CONSTRAINT producer_assertions_shape_check CHECK ('
                . "(producer_kind = 'ai' AND provider_key IS NOT NULL AND model_id IS NOT NULL "
                . 'AND producer_name IS NULL AND producer_version IS NULL) '
                . "OR (producer_kind = 'human' AND provider_key IS NULL AND model_id IS NULL "
                . 'AND model_label IS NULL AND model_version IS NULL AND producer_version IS NULL) '
                . "OR (producer_kind = 'software' AND producer_name IS NOT NULL AND provider_key IS NULL "
                . 'AND model_id IS NULL AND model_label IS NULL AND model_version IS NULL))',
        );
        DB::statement(
            'ALTER TABLE producer_assertions ADD CONSTRAINT producer_assertions_provider_key_check '
                . "CHECK (provider_key IS NULL OR provider_key ~ '^[a-z0-9][a-z0-9._-]*$')",
        );

        Schema::create('external_origin_references', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('producer_assertion_uid');
            $table->string('reference_kind', 32);
            $table->string('external_ref', 512)->nullable();
            $table->text('url')->nullable();
            $table->string('retention_class', 32)->default('sensitive');
            $table->timestampTz('redacted_at')->nullable()->index();
            $table->ulid('redacted_by_user_uid')->nullable();
            $table->timestampsTz();

            $table->foreign('producer_assertion_uid')
                ->references('uid')
                ->on('producer_assertions')
                ->cascadeOnDelete();
            $table->foreign('redacted_by_user_uid')->references('uid')->on('users');
            $table->index(
                ['producer_assertion_uid', 'reference_kind'],
                'external_origin_refs_assertion_kind_idx',
            );
        });

        DB::statement(
            'ALTER TABLE external_origin_references ADD CONSTRAINT external_origin_references_kind_check '
                . "CHECK (reference_kind IN ('artifact', 'conversation', 'session', 'source'))",
        );
        DB::statement(
            'ALTER TABLE external_origin_references ADD CONSTRAINT external_origin_references_retention_check '
                . "CHECK (retention_class IN ('sensitive'))",
        );
        DB::statement(
            'ALTER TABLE external_origin_references ADD CONSTRAINT external_origin_references_value_check '
                . 'CHECK (external_ref IS NOT NULL OR url IS NOT NULL)',
        );
        DB::statement(
            'ALTER TABLE external_origin_references ADD CONSTRAINT external_origin_references_url_length_check '
                . 'CHECK (url IS NULL OR char_length(url) <= 2048)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('external_origin_references');
        Schema::dropIfExists('producer_assertions');
        Schema::dropIfExists('page_version_ingests');
        Schema::dropIfExists('mcp_client_sessions');
    }
};
