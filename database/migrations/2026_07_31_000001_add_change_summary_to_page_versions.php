<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('page_versions', function (Blueprint $table): void {
            $table->string('change_summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('page_versions', function (Blueprint $table): void {
            $table->dropColumn('change_summary');
        });
    }
};
