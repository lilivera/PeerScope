<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('watch_sources')->update(['source_type' => 'auto']);
    }

    public function down(): void
    {
        DB::table('watch_sources')
            ->whereNotNull('list_selector')
            ->update(['source_type' => 'html']);
    }
};
