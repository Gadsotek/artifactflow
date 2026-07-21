<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->unsignedBigInteger('metadata_revision')->default(0);
        });

        DB::statement(
            'ALTER TABLE pages ADD CONSTRAINT pages_metadata_revision_check CHECK (metadata_revision >= 0)',
        );
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropColumn('metadata_revision');
        });
    }
};
