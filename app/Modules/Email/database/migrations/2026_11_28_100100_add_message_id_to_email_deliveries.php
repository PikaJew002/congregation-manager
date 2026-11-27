<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MailChannels' id for each message, so delivery events can find it later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->string('message_id')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->dropIndex(['message_id']);
            $table->dropColumn('message_id');
        });
    }
};
