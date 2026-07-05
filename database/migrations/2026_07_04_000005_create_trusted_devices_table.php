<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('user_uid')->index();
            $table->string('token_hash', 64)->unique();
            $table->string('label', 120);
            $table->string('user_agent_summary', 120);
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->foreign('user_uid')
                ->references('uid')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
