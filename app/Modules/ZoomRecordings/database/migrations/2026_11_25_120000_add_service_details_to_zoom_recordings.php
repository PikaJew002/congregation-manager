<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoom_recordings', function (Blueprint $table) {
            $table->string('service')->nullable()->after('title');
            $table->string('speaker')->nullable()->after('service');
            $table->string('torah_portion')->nullable()->after('speaker');
            $table->json('hebcal')->nullable()->after('torah_portion');
        });

        Schema::table('zoom_recording_settings', function (Blueprint $table) {
            $table->string('timezone')->default('America/New_York')->after('preferred_recording_type');
        });
    }

    public function down(): void
    {
        Schema::table('zoom_recordings', function (Blueprint $table) {
            $table->dropColumn(['service', 'speaker', 'torah_portion', 'hebcal']);
        });

        Schema::table('zoom_recording_settings', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
