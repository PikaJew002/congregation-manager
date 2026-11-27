<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What MailChannels reported after accepting each email (delivered, bounced,
 * complained), and why a recipient stopped getting mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->string('request_id')->nullable()->after('message_id')->index();
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->string('last_event')->nullable()->after('delivered_at');
        });

        Schema::table('email_recipients', function (Blueprint $table) {
            $table->text('status_reason')->nullable()->after('status');
            $table->unsignedTinyInteger('soft_bounce_count')->default(0)->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('email_recipients', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'soft_bounce_count']);
        });

        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn(['request_id', 'delivered_at', 'last_event']);
        });
    }
};
