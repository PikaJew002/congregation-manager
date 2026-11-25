<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Items added before content search have no slide text until their files are read once.
     */
    public function up(): void
    {
        Artisan::call('service-slides:index-slide-text');
    }

    public function down(): void {}
};
