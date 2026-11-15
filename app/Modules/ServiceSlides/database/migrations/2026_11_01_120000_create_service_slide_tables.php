<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slide_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('song');
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('ccli')->nullable();
            $table->text('notes')->nullable();
            $table->string('original_path');
            $table->string('original_format')->default('odp');
            $table->string('odp_path');
            $table->string('conversion_status')->default('ready');
            $table->unsignedInteger('slide_count')->default(0);
            $table->string('page_size')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });

        Schema::create('slide_source_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slide_source_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->timestamps();

            $table->unique(['slide_source_id', 'alias']);
        });

        Schema::create('slide_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('odp_path');
            $table->string('page_size')->nullable();
            $table->string('default_kind')->nullable();
            $table->boolean('is_history_tracked')->default(false);
            $table->json('preset')->nullable();
            $table->timestamps();
        });

        Schema::create('slide_template_slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slide_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('slide_index');
            $table->string('label');
            $table->string('role')->default('title');
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('service_date');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->unique(['team_id', 'service_date']);
        });

        Schema::create('service_decks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slide_template_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->string('generated_path')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('status')->default('draft');
            $table->json('warnings')->nullable();
            $table->boolean('stale')->default(true);
            $table->timestamps();
        });

        Schema::create('service_deck_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_deck_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('entry_type');
            $table->foreignId('slide_template_slide_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('slide_source_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_deck_entries');
        Schema::dropIfExists('service_decks');
        Schema::dropIfExists('services');
        Schema::dropIfExists('slide_template_slides');
        Schema::dropIfExists('slide_templates');
        Schema::dropIfExists('slide_source_aliases');
        Schema::dropIfExists('slide_sources');
    }
};
