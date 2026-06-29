<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watch_sources', function (Blueprint $table): void {
            $table->boolean('auto_ai_summary')->default(false)->after('schedule_month_days');
        });
    }

    public function down(): void
    {
        Schema::table('watch_sources', function (Blueprint $table): void {
            $table->dropColumn('auto_ai_summary');
        });
    }
};
