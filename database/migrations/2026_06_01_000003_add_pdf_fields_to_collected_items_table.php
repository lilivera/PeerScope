<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collected_items', function (Blueprint $table): void {
            $table->string('pdf_storage_path', 2048)->nullable()->after('category');
            $table->string('pdf_original_filename')->nullable()->after('pdf_storage_path');
            $table->string('pdf_mime_type', 100)->nullable()->after('pdf_original_filename');
            $table->unsignedBigInteger('pdf_file_size')->nullable()->after('pdf_mime_type');
            $table->dateTime('pdf_downloaded_at')->nullable()->after('pdf_file_size');
        });
    }

    public function down(): void
    {
        Schema::table('collected_items', function (Blueprint $table): void {
            $table->dropColumn([
                'pdf_storage_path',
                'pdf_original_filename',
                'pdf_mime_type',
                'pdf_file_size',
                'pdf_downloaded_at',
            ]);
        });
    }
};
