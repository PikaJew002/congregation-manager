<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the congregation's MailChannels account sends delivery events, as last
 * seen. Events are connected when it is the app's own webhook URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('delivery_events_url', 2048)->nullable()->after('daily_email_limit');
            $table->timestamp('delivery_events_checked_at')->nullable()->after('delivery_events_url');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['delivery_events_url', 'delivery_events_checked_at']);
        });
    }
};
