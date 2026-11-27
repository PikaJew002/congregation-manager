<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run pauses when the congregation's account cannot send (a wrong key, a
 * used-up quota) and resumes when someone fixes it. Deliveries count their
 * attempts so temporary failures retry a few times and then stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_runs', function (Blueprint $table) {
            $table->text('pause_reason')->nullable()->after('status');
            $table->timestamp('paused_at')->nullable()->after('pause_reason');
        });

        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });

        Schema::table('email_runs', function (Blueprint $table) {
            $table->dropColumn(['pause_reason', 'paused_at']);
        });
    }
};
