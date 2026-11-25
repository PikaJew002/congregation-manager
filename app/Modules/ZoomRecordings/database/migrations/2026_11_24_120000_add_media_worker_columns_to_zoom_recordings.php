<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoom_recordings', function (Blueprint $table) {
            $table->unsignedInteger('source_height')->nullable()->after('source_path');
            $table->string('media_step')->nullable()->after('failed_step');
        });
    }

    public function down(): void
    {
        Schema::table('zoom_recordings', function (Blueprint $table) {
            $table->dropColumn(['source_height', 'media_step']);
        });
    }
};
