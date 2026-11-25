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

class DeleteAndArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2-public');
        Storage::fake('files');
        $this->owner = User::factory()->create();
        $this->team = Team::factory()->create();
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    }

    public function test_deleting_removes_every_file_the_feed_entry_and_the_row(): void
    {
        $keep = $this->published('2026-11-14');
        $gone = $this->published('2026-11-21');
        $this->files($gone);
        $this->files($keep);

        $this->actingAs($this->owner)
            ->delete(route('zoom-recordings.destroy', [$this->team->slug, $gone]))
            ->assertRedirect();

        $this->assertModelMissing($gone);
        $this->assertSame([], Storage::disk('r2-public')->allFiles('recordings/'.$gone->id));
        $this->assertSame([], Storage::disk('files')->allFiles('recordings/'.$gone->id));
        $this->assertSame([$keep->id], $this->feedIds());
        Storage::disk('r2-public')->assertExists('recordings/'.$keep->id.'/playlist.m3u8');
        Storage::disk('files')->assertExists('recordings/'.$keep->id.'/source.mp4');
    }

    public function test_an_archived_or_failed_recording_can_be_deleted(): void
    {
        $archived = $this->published('2026-11-21', ['hidden' => true]);
        $failed = $this->published('2026-11-14', ['status' => 'failed', 'hls_path' => null]);

        foreach ([$archived, $failed] as $recording) {
            $this->actingAs($this->owner)
                ->delete(route('zoom-recordings.destroy', [$this->team->slug, $recording]))
                ->assertRedirect();
            $this->assertModelMissing($recording);
        }
    }

    public function test_a_recording_that_is_still_processing_cannot_be_deleted(): void
    {
        $recording = $this->published('2026-11-21', ['status' => 'transcoding', 'media_step' => 'rendition:720p', 'hls_path' => null]);
        $this->files($recording);

        $this->actingAs($this->owner)
            ->delete(route('zoom-recordings.destroy', [$this->team->slug, $recording]))
            ->assertRedirect();

        $this->assertModelExists($recording);
        Storage::disk('files')->assertExists('recordings/'.$recording->id.'/source.mp4');
    }

    public function test_another_congregations_recording_cannot_be_deleted(): void
    {
        $other = Team::factory()->create();
        $recording = ZoomRecording::query()->create([
            'team_id' => $other->id,
            'meeting_uuid' => 'other',
            'title' => 'Theirs',
            'status' => 'completed',
        ]);

        $this->actingAs($this->owner)
            ->delete(route('zoom-recordings.destroy', [$this->team->slug, $recording]))
            ->assertNotFound();

        $this->assertModelExists($recording);
    }

    public function test_archiving_takes_a_recording_off_the_feed_but_keeps_its_files(): void
    {
        $keep = $this->published('2026-11-14');
        $archive = $this->published('2026-11-21');
        $this->files($archive);

        $this->actingAs($this->owner)
            ->patch(route('zoom-recordings.update', [$this->team->slug, $archive]), ['title' => $archive->title, 'hidden' => true])
            ->assertRedirect();

        $this->assertTrue($archive->fresh()->hidden);
        $this->assertSame([$keep->id], $this->feedIds());
        Storage::disk('r2-public')->assertExists('recordings/'.$archive->id.'/playlist.m3u8');
        Storage::disk('files')->assertExists('recordings/'.$archive->id.'/source.mp4');
    }

    public function test_restoring_puts_an_archived_recording_back_in_date_order(): void
    {
        $newer = $this->published('2026-11-21', ['hidden' => true]);
        $older = $this->published('2026-11-14');

        $this->actingAs($this->owner)
            ->patch(route('zoom-recordings.update', [$this->team->slug, $newer]), ['title' => $newer->title, 'hidden' => false])
            ->assertRedirect();

        $this->assertSame([$newer->id, $older->id], $this->feedIds());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function published(string $date, array $attributes = []): ZoomRecording
    {
        $recording = ZoomRecording::query()->create($attributes + [
            'team_id' => $this->team->id,
            'meeting_uuid' => 'meeting-'.uniqid('', true),
            'title' => 'Shabbat '.$date,
            'recorded_at' => $date.' 17:00:00',
            'status' => 'completed',
            'hls_path' => 'placeholder',
        ]);

        if ($recording->hls_path === 'placeholder') {
            $recording->update(['hls_path' => $recording->directory().'/playlist.m3u8']);
        }

        if ($recording->hls_path && ! $recording->hidden) {
            (new UpdateManifest($recording->id))->handle();
        }

        return $recording;
    }

    private function files(ZoomRecording $recording): void
    {
        $dir = $recording->directory();
        foreach (['playlist.m3u8', '360p/playlist.m3u8', '360p/segment_000.ts', 'poster.jpg', 'source.mp4'] as $file) {
            Storage::disk('r2-public')->put($dir.'/'.$file, 'x');
        }
        Storage::disk('files')->put($dir.'/source.mp4', 'x');
    }

    /**
     * @return list<int>
     */
    private function feedIds(): array
    {
        return array_column(json_decode(Storage::disk('r2-public')->get('recordings/recordings.json'), true)['recordings'], 'id');
    }
}
