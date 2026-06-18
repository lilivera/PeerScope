<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_run_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('watch_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_name')->nullable();
            $table->string('source_name');
            $table->string('source_url', 2048);
            $table->timestamps();

            $table->index(['collection_run_id', 'watch_source_id']);
        });

        DB::table('collection_runs')
            ->where('target_count', 0)
            ->where('created_count', 0)
            ->where('updated_count', 0)
            ->where('error_count', 0)
            ->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_run_sources');
    }
};
