<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    private const int MAX_CONTENT_BYTES = 5 * 1024 * 1024;

    public function up(): void
    {
        // Older releases allowed values the production edge and PHP could never
        // receive. Clamp those rows before adding the durable schema invariant.
        DB::table('installation_settings')
            ->where('max_markdown_bytes', '>', self::MAX_CONTENT_BYTES)
            ->update(['max_markdown_bytes' => self::MAX_CONTENT_BYTES]);
        DB::table('installation_settings')
            ->where('max_html_bytes', '>', self::MAX_CONTENT_BYTES)
            ->update(['max_html_bytes' => self::MAX_CONTENT_BYTES]);

        DB::statement(
            'ALTER TABLE installation_settings ADD CONSTRAINT installation_settings_content_http_envelope_check '
                . 'CHECK (max_markdown_bytes <= 5242880 AND max_html_bytes <= 5242880)',
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE installation_settings DROP CONSTRAINT IF EXISTS '
                . 'installation_settings_content_http_envelope_check',
        );
    }
};
