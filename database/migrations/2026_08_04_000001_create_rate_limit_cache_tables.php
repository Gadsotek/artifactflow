<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('rate_limit_cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
            $table->index(['expiration', 'key']);
        });

        Schema::create('rate_limit_cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
            $table->index(['expiration', 'key']);
        });

        Schema::create('artifact_rate_limit_cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
            $table->index(['expiration', 'key']);
        });

        Schema::create('artifact_rate_limit_cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
            $table->index(['expiration', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_rate_limit_cache_locks');
        Schema::dropIfExists('artifact_rate_limit_cache');
        Schema::dropIfExists('rate_limit_cache_locks');
        Schema::dropIfExists('rate_limit_cache');
    }
};
