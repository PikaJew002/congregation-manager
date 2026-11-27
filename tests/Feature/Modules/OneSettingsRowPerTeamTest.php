<?php

namespace Tests\Feature\Modules;

use App\Models\Team;
use App\Modules\Email\Models\EmailSetting;
use App\Modules\ZoomRecordings\Models\ZoomRecordingSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OneSettingsRowPerTeamTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string<EmailSetting|ZoomRecordingSetting>}> */
    public static function settingsModels(): array
    {
        return [
            'email' => [EmailSetting::class],
            'zoom recordings' => [ZoomRecordingSetting::class],
        ];
    }

    /** @param class-string<EmailSetting|ZoomRecordingSetting> $model */
    #[DataProvider('settingsModels')]
    public function test_a_congregation_can_only_have_one_settings_row(string $model): void
    {
        $settings = $model::forTeam(Team::factory()->create());

        $this->expectException(UniqueConstraintViolationException::class);
        $settings->replicate()->save();
    }

    /**
     * The Email module's version of this migration ran on its table's old
     * name, weekly_email_settings, before it was renamed.
     *
     * @return array<string, array{class-string<ZoomRecordingSetting>}>
     */
    public static function migratedSettingsModels(): array
    {
        return [
            'zoom recordings' => [ZoomRecordingSetting::class],
        ];
    }

    /** @param class-string<ZoomRecordingSetting> $model */
    #[DataProvider('migratedSettingsModels')]
    public function test_the_migration_keeps_each_congregations_oldest_row(string $model): void
    {
        $table = (new $model)->getTable();
        Schema::table($table, fn (Blueprint $table) => $table->dropUnique(['team_id']));

        $kept = $model::forTeam($first = Team::factory()->create());
        $kept->replicate()->save();
        $kept->replicate()->save();
        $other = $model::forTeam(Team::factory()->create());

        $migration = glob(app_path("Modules/ZoomRecordings/database/migrations/*_add_unique_team_index_to_{$table}.php"))[0];
        (require $migration)->up();

        $this->assertSame([$kept->id], $model::query()->where('team_id', $first->id)->pluck('id')->all());
        $this->assertTrue($model::query()->whereKey($other->id)->exists());
        $this->assertTrue(Schema::hasIndex($table, ['team_id'], 'unique'));
    }
}
