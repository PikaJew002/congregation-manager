<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoom_recordings', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('hls_path');
        });

        // Recordings hidden before this change were archived at their last update.
        DB::table('zoom_recordings')
            ->where('hidden', true)
            ->update(['archived_at' => DB::raw('COALESCE(updated_at, CURRENT_TIMESTAMP)')]);

        Schema::table('zoom_recordings', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    }

    public function down(): void
    {
        Schema::table('zoom_recordings', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('hls_path');
        });

        DB::table('zoom_recordings')->whereNotNull('archived_at')->update(['hidden' => true]);

        Schema::table('zoom_recordings', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
