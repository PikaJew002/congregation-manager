<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sends are now a short paced run, a few seconds between emails, so the send
 * window setting goes away. Deliveries are handed to the queue a minute at a
 * time; queued_at records when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_email_settings', function (Blueprint $table) {
            $table->dropColumn('window_minutes');
        });

        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable()->after('scheduled_at');
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn('queued_at');
        });

        Schema::table('weekly_email_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('window_minutes')->default(150)->after('send_time');
        });
    }
};
