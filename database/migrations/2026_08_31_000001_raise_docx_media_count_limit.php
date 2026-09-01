<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE docx_version_facts DROP CONSTRAINT docx_version_facts_counts_check');
        DB::statement(
            'ALTER TABLE docx_version_facts ADD CONSTRAINT docx_version_facts_counts_check CHECK ('
                . 'package_entry_count BETWEEN 1 AND 2000 '
                . 'AND expanded_bytes BETWEEN 1 AND 67108864 '
                . 'AND relationship_count <= 4000 '
                . 'AND media_count <= 1024 '
                . 'AND external_hyperlink_count <= 1000 '
                . 'AND page_count BETWEEN 1 AND 250)',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE docx_version_facts DROP CONSTRAINT docx_version_facts_counts_check');
        DB::statement(
            'ALTER TABLE docx_version_facts ADD CONSTRAINT docx_version_facts_counts_check CHECK ('
                . 'package_entry_count BETWEEN 1 AND 2000 '
                . 'AND expanded_bytes BETWEEN 1 AND 67108864 '
                . 'AND relationship_count <= 4000 '
                . 'AND media_count <= 1024 '
                . 'AND external_hyperlink_count <= 1000 '
                . 'AND page_count BETWEEN 1 AND 250)',
        );
    }
};
