<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('producer_assertions', function (Blueprint $table): void {
            $table->string('reported_provider', 80)->nullable()->after('producer_version');
            $table->jsonb('claim_extensions')->default('[]')->after('model_version');
        });

        DB::statement(
            'UPDATE producer_assertions SET reported_provider = provider_key '
                . "WHERE producer_kind = 'ai' AND provider_key IS NOT NULL",
        );
        DB::statement(
            'ALTER TABLE producer_assertions DROP CONSTRAINT producer_assertions_shape_check',
        );
        DB::statement(
            'ALTER TABLE producer_assertions ADD CONSTRAINT producer_assertions_shape_check CHECK ('
                // A reference-only AI claim has no fact on this parent row; the
                // application validates non-empty claims before inserting the
                // assertion and its child references in the same transaction.
                . "(producer_kind = 'ai' AND producer_name IS NULL AND producer_version IS NULL) "
                . "OR (producer_kind = 'human' AND provider_key IS NULL AND model_id IS NULL "
                . 'AND model_label IS NULL AND model_version IS NULL AND producer_version IS NULL) '
                . "OR (producer_kind = 'software' AND producer_name IS NOT NULL AND provider_key IS NULL "
                . 'AND model_id IS NULL AND model_label IS NULL AND model_version IS NULL))',
        );
        DB::statement(
            'ALTER TABLE producer_assertions ADD CONSTRAINT producer_assertions_reported_provider_check '
                . 'CHECK (reported_provider IS NULL OR provider_key IS NOT NULL)',
        );
        DB::statement(
            'ALTER TABLE producer_assertions ADD CONSTRAINT producer_assertions_claim_extensions_check '
                . "CHECK (jsonb_typeof(claim_extensions) = 'array' "
                . 'AND jsonb_array_length(claim_extensions) <= 16)',
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE producer_assertions DROP CONSTRAINT producer_assertions_claim_extensions_check',
        );
        DB::statement(
            'ALTER TABLE producer_assertions DROP CONSTRAINT producer_assertions_reported_provider_check',
        );
        DB::statement(
            'ALTER TABLE producer_assertions DROP CONSTRAINT producer_assertions_shape_check',
        );
        DB::statement(
            'ALTER TABLE producer_assertions ADD CONSTRAINT producer_assertions_shape_check CHECK ('
                . "(producer_kind = 'ai' AND provider_key IS NOT NULL AND model_id IS NOT NULL "
                . 'AND producer_name IS NULL AND producer_version IS NULL) '
                . "OR (producer_kind = 'human' AND provider_key IS NULL AND model_id IS NULL "
                . 'AND model_label IS NULL AND model_version IS NULL AND producer_version IS NULL) '
                . "OR (producer_kind = 'software' AND producer_name IS NOT NULL AND provider_key IS NULL "
                . 'AND model_id IS NULL AND model_label IS NULL AND model_version IS NULL)) NOT VALID',
        );

        Schema::table('producer_assertions', function (Blueprint $table): void {
            $table->dropColumn(['reported_provider', 'claim_extensions']);
        });
    }
};
