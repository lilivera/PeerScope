<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collected_items', function (Blueprint $table): void {
            $table->text('ai_summary')->nullable();
            $table->string('ai_summary_model', 100)->nullable();
            $table->timestamp('ai_summary_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('collected_items', function (Blueprint $table): void {
            $table->dropColumn([
                'ai_summary',
                'ai_summary_model',
                'ai_summary_generated_at',
            ]);
        });
    }
};
