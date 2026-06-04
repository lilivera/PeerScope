<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'login_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('login_id')->nullable()->after('name');
            });

            DB::table('users')
                ->orderBy('id')
                ->select('id')
                ->get()
                ->each(fn ($user) => DB::table('users')
                    ->where('id', $user->id)
                    ->update(['login_id' => 'user'.$user->id]));

            Schema::table('users', function (Blueprint $table): void {
                $table->unique('login_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'login_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique(['login_id']);
                $table->dropColumn('login_id');
            });
        }
    }
};
