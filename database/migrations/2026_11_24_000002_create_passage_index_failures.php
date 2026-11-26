<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('passage_index_failures', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->foreignUlid('page_version_uid')->references('uid')->on('page_versions')->cascadeOnDelete();
            $table->bigInteger('generation');
            $table->integer('extractor_revision');
            $table->string('reason', 32);
            $table->integer('attempts');
            $table->timestampTz('retry_at');
            $table->unique(['page_version_uid', 'generation', 'extractor_revision'], 'passage_failures_version_generation_unique');
            $table->index(['generation', 'uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passage_index_failures');
    }
};
