<?php

use App\Models\UserImportSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_import_settings', 'scheduled_time')) {
            Schema::table('user_import_settings', function (Blueprint $table): void {
                $table->string('scheduled_time', 5)->default(UserImportSetting::defaultScheduledTime())->after('is_enabled');
            });
        }

        DB::table('user_import_settings')
            ->whereNull('scheduled_time')
            ->orWhere('scheduled_time', '')
            ->update(['scheduled_time' => UserImportSetting::defaultScheduledTime()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_import_settings', 'scheduled_time')) {
            Schema::table('user_import_settings', function (Blueprint $table): void {
                $table->dropColumn('scheduled_time');
            });
        }
    }
};
