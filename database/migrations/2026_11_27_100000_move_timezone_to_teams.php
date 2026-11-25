<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One time zone per congregation, replacing the separate Zoom Recordings and
 * Weekly Email settings. Each congregation keeps its Zoom Recordings zone, or
 * its Weekly Email zone when it has no recording settings.
 */
return new class extends Migration
{
    private const DEFAULT = 'America/New_York';

    /** @var array<string, string> */
    private array $moduleTables = [
        'zoom_recording_settings' => 'preferred_recording_type',
        'weekly_email_settings' => 'send_time',
    ];

    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('timezone')->default(self::DEFAULT)->after('slug');
        });

        // Weekly Email first so Zoom Recordings wins where both are set.
        foreach (array_reverse(array_keys($this->moduleTables)) as $settings) {
            if (! Schema::hasColumn($settings, 'timezone')) {
                continue;
            }

            DB::table($settings)->whereNotNull('timezone')->orderBy('team_id')
                ->each(fn (object $row) => DB::table('teams')->where('id', $row->team_id)->update(['timezone' => $row->timezone]));
        }

        foreach (array_keys($this->moduleTables) as $settings) {
            if (Schema::hasColumn($settings, 'timezone')) {
                Schema::table($settings, fn (Blueprint $table) => $table->dropColumn('timezone'));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->moduleTables as $settings => $after) {
            if (! Schema::hasTable($settings) || Schema::hasColumn($settings, 'timezone')) {
                continue;
            }

            Schema::table($settings, fn (Blueprint $table) => $table->string('timezone')->default(self::DEFAULT)->after($after));

            DB::table('teams')->orderBy('id')->each(fn (object $team) => DB::table($settings)
                ->where('team_id', $team->id)->update(['timezone' => $team->timezone]));
        }

        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('timezone'));
    }
};
