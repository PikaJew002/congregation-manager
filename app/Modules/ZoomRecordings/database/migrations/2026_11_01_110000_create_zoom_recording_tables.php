<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoom_recording_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete()->unique();
            $table->text('webhook_secret')->nullable();
            $table->string('r2_prefix')->default('recordings');
            $table->string('public_base_url')->nullable();
            $table->string('preferred_recording_type')->default('shared_screen_with_speaker_view');
            $table->timestamps();
        });

        Schema::create('zoom_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('meeting_uuid');
            $table->string('meeting_id')->nullable();
            $table->string('title');
            $table->timestamp('recorded_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('download_url')->nullable();
            $table->text('download_token')->nullable();
            $table->string('recording_file_id')->nullable();
            $table->string('status')->default('received');
            $table->string('failed_step')->nullable();
            $table->text('error')->nullable();
            $table->string('source_path')->nullable();
            $table->string('hls_path')->nullable();
            $table->boolean('hidden')->default(false);
            $table->json('log')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'meeting_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoom_recordings');
        Schema::dropIfExists('zoom_recording_settings');
    }
};
