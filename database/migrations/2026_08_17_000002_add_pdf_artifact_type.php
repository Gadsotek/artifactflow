<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE pages DROP CONSTRAINT pages_type_check');
        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_type_check '
                . "CHECK (type IN ('markdown', 'html_artifact', 'image', 'pdf'))",
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pages DROP CONSTRAINT pages_type_check');
        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_type_check '
                . "CHECK (type IN ('markdown', 'html_artifact', 'image'))",
        );
    }
};
