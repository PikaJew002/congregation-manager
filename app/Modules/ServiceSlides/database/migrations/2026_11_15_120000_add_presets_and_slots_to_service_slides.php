<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slide_sources', function (Blueprint $table) {
            $table->json('slide_lines')->nullable()->after('slide_count');
            $table->timestamp('file_updated_at')->nullable()->after('page_size');
        });

        Schema::table('slide_templates', function (Blueprint $table) {
            $table->timestamp('file_updated_at')->nullable()->after('preset');
        });

        Schema::table('slide_template_slides', function (Blueprint $table) {
            $table->boolean('missing')->default(false)->after('role');
        });

        Schema::table('service_decks', function (Blueprint $table) {
            $table->boolean('blocked')->default(false)->after('stale');
        });

        Schema::table('service_deck_entries', function (Blueprint $table) {
            $table->string('slot_key')->nullable()->after('slide_source_id');
            $table->boolean('auto')->default(false)->after('slot_key');
        });
    }

    public function down(): void
    {
        Schema::table('service_deck_entries', function (Blueprint $table) {
            $table->dropColumn(['slot_key', 'auto']);
        });

        Schema::table('service_decks', function (Blueprint $table) {
            $table->dropColumn('blocked');
        });

        Schema::table('slide_template_slides', function (Blueprint $table) {
            $table->dropColumn('missing');
        });

        Schema::table('slide_templates', function (Blueprint $table) {
            $table->dropColumn('file_updated_at');
        });

        Schema::table('slide_sources', function (Blueprint $table) {
            $table->dropColumn(['slide_lines', 'file_updated_at']);
        });
    }
};
