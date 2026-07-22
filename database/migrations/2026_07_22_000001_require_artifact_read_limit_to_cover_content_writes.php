<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    public function up(): void
    {
        DB::statement(
            'UPDATE installation_settings '
                . 'SET artifact_max_bytes = GREATEST(artifact_max_bytes, max_markdown_bytes, max_html_bytes) '
                . 'WHERE artifact_max_bytes < GREATEST(max_markdown_bytes, max_html_bytes)',
        );

        DB::statement(
            'ALTER TABLE installation_settings ADD CONSTRAINT installation_settings_content_read_limit_check '
                . 'CHECK (artifact_max_bytes >= max_markdown_bytes AND artifact_max_bytes >= max_html_bytes)',
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE installation_settings DROP CONSTRAINT IF EXISTS '
                . 'installation_settings_content_read_limit_check',
        );
    }
};
