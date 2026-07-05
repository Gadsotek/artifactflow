<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('uid')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestampsTz();
            $table->boolean('is_system_admin')->default(false)->index();
            $table->string('theme_preference')->default('system')->index();
            $table->text('two_factor_secret')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->unsignedBigInteger('two_factor_last_used_timestep')->nullable();
            $table->boolean('two_factor_required')->default(false);
            $table->boolean('is_service_account')->default(false)->index();
        });

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_theme_preference_check '
                . "CHECK (theme_preference IN ('light', 'dark', 'system'))",
        );

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        // Application code normalizes emails to lowercase before writes; this
        // functional unique index makes case-uniqueness a database guarantee
        // and serves LOWER(email) lookups.
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (LOWER(email))');

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->ulid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
