<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('status')->default('subscribed');
            $table->string('unsubscribe_token', 64)->unique();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'email']);
        });

        Schema::create('weekly_email_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('subject')->default('This week on Zoom');
            $table->text('body');
            $table->string('zoom_link')->nullable();
            $table->string('from_address');
            $table->string('from_name');
            $table->string('reply_to')->nullable();
            $table->unsignedTinyInteger('send_day')->default(0);
            $table->string('send_time')->default('09:00');
            $table->string('timezone')->default('America/New_York');
            $table->unsignedSmallInteger('window_minutes')->default(150);
            $table->timestamps();
        });

        Schema::create('email_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_for');
            $table->string('status')->default('scheduled');
            $table->string('subject');
            $table->text('body');
            $table->timestamps();

            $table->unique(['team_id', 'scheduled_for']);
        });

        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
        Schema::dropIfExists('email_runs');
        Schema::dropIfExists('weekly_email_settings');
        Schema::dropIfExists('email_recipients');
    }
};
