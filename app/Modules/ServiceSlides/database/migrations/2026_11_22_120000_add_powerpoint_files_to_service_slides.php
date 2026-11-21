<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slide_sources', function (Blueprint $table) {
            $table->renameColumn('odp_path', 'file_path');
        });

        Schema::table('slide_templates', function (Blueprint $table) {
            $table->renameColumn('odp_path', 'file_path');
            $table->string('format')->default('odp')->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('slide_templates', function (Blueprint $table) {
            $table->dropColumn('format');
            $table->renameColumn('file_path', 'odp_path');
        });

        Schema::table('slide_sources', function (Blueprint $table) {
            $table->renameColumn('file_path', 'odp_path');
        });
    }
};
