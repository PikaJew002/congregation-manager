<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each congregation has one row of Zoom Recordings settings. The table was created with
 * unique() on the foreign key instead of the column, so the index was never made.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('zoom_recording_settings', ['team_id'], 'unique')) {
            return;
        }

        // Where a congregation has extra rows, keep its oldest, the one forTeam() has been reading.
        DB::table('zoom_recording_settings')
            ->selectRaw('team_id, MIN(id) as keep_id')
            ->groupBy('team_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(fn (object $row) => DB::table('zoom_recording_settings')
                ->where('team_id', $row->team_id)
                ->where('id', '>', $row->keep_id)
                ->delete());

        Schema::table('zoom_recording_settings', function (Blueprint $table) {
            $table->unique('team_id');
        });
    }

    public function down(): void
    {
        // The index should always have existed, so it stays.
    }
};
