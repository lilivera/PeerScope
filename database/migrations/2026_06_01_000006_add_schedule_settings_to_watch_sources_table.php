<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watch_sources', function (Blueprint $table): void {
            $table->string('schedule_type', 20)->default('interval')->after('crawl_interval_minutes');
            $table->string('schedule_time', 5)->nullable()->after('schedule_type');
            $table->json('schedule_weekdays')->nullable()->after('schedule_time');
            $table->json('schedule_month_days')->nullable()->after('schedule_weekdays');

            $table->index(['schedule_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('watch_sources', function (Blueprint $table): void {
            $table->dropIndex(['schedule_type', 'is_active']);
            $table->dropColumn([
                'schedule_type',
                'schedule_time',
                'schedule_weekdays',
                'schedule_month_days',
            ]);
        });
    }
};
