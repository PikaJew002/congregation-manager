<?php

namespace Tests\Feature\ZoomRecordings;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpdateManifestTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2-public');
        $user = User::factory()->create();
        $this->team = Team::factory()->create();
        $this->team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    }

    public function test_editing_an_older_recording_keeps_the_newest_recording_first(): void
    {
        $older = $this->recording('2026-11-14');
        $newer = $this->recording('2026-11-21');
        (new UpdateManifest($older->id))->handle();
        (new UpdateManifest($newer->id))->handle();

        $older->update(['title' => 'Renamed']);
        (new UpdateManifest($older->id))->handle();

        $this->assertSame([$newer->id, $older->id], $this->ids());
        $this->assertSame('Renamed', $this->manifest()[1]['title']);
    }

    public function test_a_late_arriving_older_recording_is_placed_by_its_date(): void
    {
        $newest = $this->recording('2026-11-21');
        $oldest = $this->recording('2026-10-31');
        $middle = $this->recording('2026-11-07');

        foreach ([$newest, $oldest, $middle] as $recording) {
            (new UpdateManifest($recording->id))->handle();
        }

        $this->assertSame([$newest->id, $middle->id, $oldest->id], $this->ids());
    }

    public function test_same_day_recordings_put_the_later_one_first_and_undated_ones_last(): void
    {
        $undated = $this->recording(null);
        $first = $this->recording('2026-11-21');
        $second = $this->recording('2026-11-21');

        foreach ([$undated, $second, $first] as $recording) {
            (new UpdateManifest($recording->id))->handle();
        }

        $this->assertSame([$second->id, $first->id, $undated->id], $this->ids());
    }

    public function test_hiding_a_recording_removes_it_and_keeps_the_rest_in_date_order(): void
    {
        $a = $this->recording('2026-11-07');
        $b = $this->recording('2026-11-21');
        $c = $this->recording('2026-11-14');
        foreach ([$a, $b, $c] as $recording) {
            (new UpdateManifest($recording->id))->handle();
        }

        $b->update(['hidden' => true]);
        (new UpdateManifest($b->id))->handle();

        $this->assertSame([$c->id, $a->id], $this->ids());
    }

    private function recording(?string $date): ZoomRecording
    {
        return ZoomRecording::query()->create([
            'team_id' => $this->team->id,
            'meeting_uuid' => 'meeting-'.uniqid('', true),
            'title' => 'Shabbat',
            'recorded_at' => $date ? $date.' 17:00:00' : null,
            'status' => 'uploaded',
            'hls_path' => 'recordings/x/playlist.m3u8',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manifest(): array
    {
        return json_decode(Storage::disk('r2-public')->get('recordings/recordings.json'), true)['recordings'];
    }

    /**
     * @return list<int>
     */
    private function ids(): array
    {
        return array_column($this->manifest(), 'id');
    }
}
