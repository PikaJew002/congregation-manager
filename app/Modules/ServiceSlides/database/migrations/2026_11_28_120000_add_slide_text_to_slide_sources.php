<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every line on every slide, for excerpts, and the same text normalized for searching.
     */
    public function up(): void
    {
        Schema::table('slide_sources', function (Blueprint $table) {
            $table->json('slide_text')->nullable()->after('slide_lines');
            $table->longText('search_text')->nullable()->after('slide_text');
        });
    }

    public function down(): void
    {
        Schema::table('slide_sources', function (Blueprint $table) {
            $table->dropColumn(['slide_text', 'search_text']);
        });
    }
};
