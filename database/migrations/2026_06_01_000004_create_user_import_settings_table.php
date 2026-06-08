<?php

use App\Models\UserImportSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_import_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('scheduled_time', 5)->default(UserImportSetting::defaultScheduledTime());
            $table->string('import_directory', 1024)->nullable();
            $table->string('processed_directory', 1024)->nullable();
            $table->string('failed_directory', 1024)->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->text('last_result')->nullable();
            $table->timestamps();
        });

        UserImportSetting::query()->create([
            'id' => 1,
            'is_enabled' => false,
            'scheduled_time' => UserImportSetting::defaultScheduledTime(),
            'import_directory' => UserImportSetting::defaultImportDirectory(),
            'processed_directory' => UserImportSetting::defaultProcessedDirectory(),
            'failed_directory' => UserImportSetting::defaultFailedDirectory(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_import_settings');
    }
};
