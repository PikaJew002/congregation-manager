<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = collect(['zoom_account_id', 'zoom_client_id', 'zoom_client_secret', 'delete_from_zoom'])
            ->filter(fn (string $column) => Schema::hasColumn('zoom_recording_settings', $column))
            ->all();

        if ($columns === []) {
            return;
        }

        Schema::table('zoom_recording_settings', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        Schema::table('zoom_recording_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('zoom_recording_settings', 'zoom_account_id')) {
                $table->text('zoom_account_id')->nullable();
            }
            if (! Schema::hasColumn('zoom_recording_settings', 'zoom_client_id')) {
                $table->text('zoom_client_id')->nullable();
            }
            if (! Schema::hasColumn('zoom_recording_settings', 'zoom_client_secret')) {
                $table->text('zoom_client_secret')->nullable();
            }
            if (! Schema::hasColumn('zoom_recording_settings', 'delete_from_zoom')) {
                $table->boolean('delete_from_zoom')->default(false);
            }
        });
    }
};
