<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Songs uploaded before import-time formatting relied on the deck builder to whiten their text.
     */
    public function up(): void
    {
        Artisan::call('service-slides:format-songs');
    }

    public function down(): void {}
};
