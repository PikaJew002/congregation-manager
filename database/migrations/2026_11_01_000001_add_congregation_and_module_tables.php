<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        DB::table('teams')->whereNull('uuid')->orderBy('id')->each(function (object $team): void {
            DB::table('teams')->where('id', $team->id)->update(['uuid' => (string) Str::uuid()]);
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('role');
        });

        Schema::create('team_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'module_key']);
        });

        Schema::create('team_module_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->timestamps();

            $table->unique(['team_id', 'user_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_module_user');
        Schema::dropIfExists('team_modules');
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
