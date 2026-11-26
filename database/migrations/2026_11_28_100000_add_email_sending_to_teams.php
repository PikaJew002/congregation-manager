<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each congregation sends mail through its own MailChannels account. The key
 * and the domain it sends from belong to the congregation, like the time zone,
 * so every module that sends mail uses the same account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->text('mailchannels_api_key')->nullable()->after('timezone');
            $table->string('mailchannels_account_id')->nullable()->after('mailchannels_api_key');
            $table->string('sending_domain')->nullable()->after('mailchannels_account_id');
            $table->string('dkim_selector')->nullable()->after('sending_domain');
            $table->json('dkim_dns_record')->nullable()->after('dkim_selector');
            $table->json('domain_check')->nullable()->after('dkim_selector');
            $table->timestamp('domain_checked_at')->nullable()->after('domain_check');
            $table->unsignedInteger('daily_email_limit')->default(100)->after('domain_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'mailchannels_api_key',
                'mailchannels_account_id',
                'sending_domain',
                'dkim_selector',
                'dkim_dns_record',
                'domain_check',
                'domain_checked_at',
                'daily_email_limit',
            ]);
        });
    }
};
