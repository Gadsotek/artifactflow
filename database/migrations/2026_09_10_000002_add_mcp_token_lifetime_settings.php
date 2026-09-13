<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->unsignedInteger('mcp_read_token_max_ttl_days')->default(365);
            $table->unsignedInteger('mcp_write_token_max_ttl_days')->default(90);
        });

        DB::statement(
            'ALTER TABLE installation_settings ADD CONSTRAINT installation_settings_mcp_token_ttl_check '
                . 'CHECK (mcp_read_token_max_ttl_days BETWEEN 1 AND 365 '
                . 'AND mcp_write_token_max_ttl_days BETWEEN 1 AND 365)',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE installation_settings DROP CONSTRAINT installation_settings_mcp_token_ttl_check');

        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn(['mcp_read_token_max_ttl_days', 'mcp_write_token_max_ttl_days']);
        });
    }
};
