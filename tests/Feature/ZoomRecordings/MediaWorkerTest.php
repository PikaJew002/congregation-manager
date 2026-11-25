<?php

namespace Tests\Feature\ZoomRecordings;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ZoomRecordings\Actions\DispatchRecordingPipeline;
use App\Modules\ZoomRecordings\Actions\MediaWorker;
use App\Modules\ZoomRecordings\Jobs\DownloadRecording;
use App\Modules\ZoomRecordings\Jobs\TranscodeAndUpload;
use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MediaWorkerTest extends TestCase
{
    use RefreshDatabase;

    private int $workerStatus = 202;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.media_worker' => ['url' => 'https://media.example', 'secret' => 'worker-secret']]);
        Http::fake(['media.example/*' => fn () => Http::response(['accepted' => $this->workerStatus === 202], $this->workerStatus)]);
        Bus::fake();
    }

    public function test_the_pipeline_sends_a_signed_download_step_instead_of_queueing(): void
    {
        $recording = $this->recording();

        app(DispatchRecordingPipeline::class)->handle($recording);

        Bus::assertNothingDispatched();
        Http::assertSent(function (Request $request) use ($recording) {
            return $request->url() === 'https://media.example/jobs'
                && $request->header(MediaWorker::SIGNATURE_HEADER)[0] === hash_hmac('sha256', $request->body(), 'worker-secret')
                && $request['step'] === 'download'
                && $request['recording_id'] === $recording->id
                && $request['directory'] === 'recordings/'.$recording->id
                && $request['download_token'] === 'zoom-token';
        });
        $this->assertSame('downloading', $recording->fresh()->status);
        $this->assertSame('download', $recording->fresh()->media_step);
    }

    public function test_a_1080p_download_sends_the_480p_rendition_without_the_zoom_token(): void
    {
        $recording = $this->recording(['media_step' => 'download', 'status' => 'downloading']);

        $this->report($recording, ['step' => 'download', 'ok' => true, 'height' => 1080, 'source_path' => 'recordings/'.$recording->id.'/source.mp4'])
            ->assertOk();

        $recording->refresh();
        $this->assertSame(1080, $recording->source_height);
        $this->assertSame('recordings/'.$recording->id.'/source.mp4', $recording->source_path);
        $this->assertSame('rendition:480p', $recording->media_step);
        Http::assertSent(fn (Request $request) => $request['step'] === 'rendition'
            && $request['rendition'] === '480p'
            && ! isset($request['download_token']));
    }

    public function test_a_360p_source_skips_every_rendition_and_goes_to_the_poster(): void
    {
        $recording = $this->recording(['media_step' => 'download', 'status' => 'downloading']);

        $this->report($recording, ['step' => 'download', 'ok' => true, 'height' => 360])->assertOk();

        $this->assertSame('poster', $recording->fresh()->media_step);
        Http::assertNotSent(fn (Request $request) => $request['step'] === 'rendition');
    }

    public function test_the_last_rendition_for_720p_moves_to_the_poster(): void
    {
        $recording = $this->recording(['media_step' => 'rendition:720p', 'source_path' => 'x', 'source_height' => 720]);

        $this->report($recording, ['step' => 'rendition', 'rendition' => '720p', 'ok' => true])->assertOk();

        $this->assertSame('poster', $recording->fresh()->media_step);
    }

    public function test_the_poster_finishes_with_the_master_playlist_and_manifest(): void
    {
        Storage::fake('r2-public');
        $recording = $this->recording(['media_step' => 'poster', 'source_path' => 'x', 'source_height' => 720]);

        $this->report($recording, ['step' => 'poster', 'ok' => true])->assertOk();

        $recording->refresh();
        $this->assertSame('completed', $recording->status);
        $this->assertNull($recording->media_step);
        $this->assertSame('recordings/'.$recording->id.'/playlist.m3u8', $recording->hls_path);
        $master = Storage::disk('r2-public')->get($recording->hls_path);
        $this->assertStringContainsString("RESOLUTION=854x480\n480p/playlist.m3u8", $master);
        $this->assertStringContainsString("RESOLUTION=1280x720\n720p/playlist.m3u8", $master);
        $this->assertStringNotContainsString('1080p', $master);
        Storage::disk('r2-public')->assertExists('recordings/recordings.json');
    }

    public function test_a_failed_step_is_marked_for_retry_and_retry_resends_it(): void
    {
        [$team, $owner] = $this->owner();
        $recording = $this->recording(['media_step' => 'rendition:720p', 'source_path' => 'x', 'source_height' => 1080], $team);

        $this->report($recording, ['step' => 'rendition', 'rendition' => '720p', 'ok' => false, 'error' => 'FFmpeg failed'])
            ->assertOk();

        $recording->refresh();
        $this->assertSame('failed', $recording->status);
        $this->assertSame('rendition:720p', $recording->failed_step);
        $this->assertSame('FFmpeg failed', $recording->error);

        $this->actingAs($owner)->post(route('zoom-recordings.retry', [$team->slug, $recording]))->assertRedirect();

        Http::assertSent(fn (Request $request) => $request['step'] === 'rendition' && $request['rendition'] === '720p');
        $this->assertSame('rendition:720p', $recording->fresh()->media_step);
    }

    public function test_a_callback_needs_a_valid_signature(): void
    {
        $recording = $this->recording(['media_step' => 'download']);
        $body = json_encode(['recording_id' => $recording->id, 'step' => 'download', 'ok' => true, 'sent_at' => time()]);

        $this->call('POST', route('zoom-recordings.media-worker'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MEDIA_SIGNATURE' => 'nope',
        ], $body)->assertUnauthorized();

        $this->assertSame('download', $recording->fresh()->media_step);
    }

    public function test_a_callback_for_a_step_that_is_not_running_is_ignored(): void
    {
        $recording = $this->recording(['media_step' => 'poster']);

        $this->report($recording, ['step' => 'download', 'ok' => true, 'height' => 1080])->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_an_unreachable_worker_marks_the_step_failed(): void
    {
        $this->workerStatus = 500;
        $recording = $this->recording();

        app(DispatchRecordingPipeline::class)->handle($recording);

        $this->assertSame('failed', $recording->fresh()->status);
        $this->assertSame('download', $recording->fresh()->failed_step);
    }

    public function test_a_step_that_never_reports_back_is_failed_for_retry(): void
    {
        $stuck = $this->recording(['media_step' => 'rendition:1080p', 'status' => 'transcoding']);
        $fresh = $this->recording(['media_step' => 'rendition:720p', 'status' => 'transcoding']);
        ZoomRecording::query()->whereKey($stuck->id)->update(['updated_at' => now()->subHours(MediaWorker::STALL_HOURS + 1)]);

        $this->assertSame(1, app(MediaWorker::class)->failStalled());

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('rendition:1080p', $stuck->fresh()->failed_step);
        $this->assertSame('transcoding', $fresh->fresh()->status);
    }

    public function test_without_a_worker_url_the_local_queue_runs(): void
    {
        config(['services.media_worker.url' => null]);

        app(DispatchRecordingPipeline::class)->handle($this->recording());

        Http::assertNothingSent();
        Bus::assertChained([
            DownloadRecording::class,
            TranscodeAndUpload::class,
            UpdateManifest::class,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function report(ZoomRecording $recording, array $payload): TestResponse
    {
        $body = json_encode(['recording_id' => $recording->id, 'sent_at' => time()] + $payload);

        return $this->call('POST', route('zoom-recordings.media-worker'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MEDIA_SIGNATURE' => hash_hmac('sha256', $body, 'worker-secret'),
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recording(array $attributes = [], ?Team $team = null): ZoomRecording
    {
        $team ??= $this->owner()[0];

        return ZoomRecording::query()->create($attributes + [
            'team_id' => $team->id,
            'meeting_uuid' => 'meeting-'.uniqid(),
            'title' => 'Shabbat',
            'download_url' => 'https://zoom.example/download',
            'download_token' => 'zoom-token',
            'status' => 'failed',
        ]);
    }

    /**
     * @return array{0: Team, 1: User}
     */
    private function owner(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

        return [$team, $user];
    }
}
