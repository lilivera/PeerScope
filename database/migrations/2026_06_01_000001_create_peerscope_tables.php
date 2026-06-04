<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('official_url', 2048)->nullable();
            $table->text('memo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('watch_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source_name');
            $table->string('source_url', 2048);
            $table->string('source_type', 20);
            $table->string('list_selector', 1024)->nullable();
            $table->string('title_selector', 1024)->nullable();
            $table->string('url_selector', 1024)->nullable();
            $table->string('date_selector', 1024)->nullable();
            $table->string('body_selector', 1024)->nullable();
            $table->unsignedInteger('crawl_interval_minutes')->default(60);
            $table->dateTime('last_crawled_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
            $table->index(['source_type', 'is_active']);
            $table->index('last_crawled_at');
        });

        Schema::create('collected_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('watch_source_id')->constrained()->cascadeOnDelete();
            $table->string('title', 1000);
            $table->string('url', 2048);
            $table->char('url_hash', 64)->unique('unique_url_hash');
            $table->char('content_hash', 64)->nullable()->index();
            $table->dateTime('published_at')->nullable()->index('idx_published_at');
            $table->dateTime('detected_at')->index();
            $table->text('summary')->nullable();
            $table->longText('body_text')->nullable();
            $table->string('category', 100)->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'detected_at'], 'idx_company_detected');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('collected_items', function (Blueprint $table): void {
                $table->fullText(['title', 'summary', 'body_text'], 'fulltext_items');
            });
        }

        Schema::create('collection_runs', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 20)->default('running')->index();
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('collection_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('watch_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error_type');
            $table->text('error_message');
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index('occurred_at');
        });

        Schema::create('item_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collected_item_id')->constrained()->cascadeOnDelete();
            $table->dateTime('read_at');
            $table->timestamps();

            $table->unique(['user_id', 'collected_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_reads');
        Schema::dropIfExists('collection_errors');
        Schema::dropIfExists('collection_runs');
        Schema::dropIfExists('collected_items');
        Schema::dropIfExists('watch_sources');
        Schema::dropIfExists('companies');
    }
};
