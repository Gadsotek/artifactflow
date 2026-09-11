<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('personal_page_states', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->foreignUlid('user_uid')->constrained('users', 'uid')->cascadeOnDelete();
            $table->foreignUlid('page_uid')->constrained('pages', 'uid')->cascadeOnDelete();
            $table->timestampTz('last_opened_at')->nullable();
            $table->timestampTz('favorited_at')->nullable();
            $table->unique(['user_uid', 'page_uid']);
            $table->index(['user_uid', 'last_opened_at']);
            $table->index(['user_uid', 'favorited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_page_states');
    }
};
