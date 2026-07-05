<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('mcp_access_tokens', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->ulid('principal_user_uid');
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->jsonb('scopes');
            $table->jsonb('workspace_uids')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('principal_user_uid')->references('uid')->on('users')->cascadeOnDelete();
            $table->index(['principal_user_uid', 'revoked_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_access_tokens');
    }
};
