<?php

namespace Tests\Feature\ZoomRecordings;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ZoomRecordings\Jobs\DownloadRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DownloadRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_follows_redirects_and_sends_the_download_token_each_time(): void
    {
        Storage::fake('files');
        $recording = $this->recording();
        $mp4 = "\x00\x00\x00\x18ftypisom";

        Http::fake(function ($request) use ($mp4) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer download-secret'));

            if ($request->url() === 'https://zoom.example/rec/webhook_download/file') {
                return Http::response('', 302, ['Location' => 'https://files.zoom.example/rec.mp4']);
            }

            $this->assertSame('https://files.zoom.example/rec.mp4', $request->url());

            return Http::response($mp4, 200, ['Content-Type' => 'video/mp4']);
        });

        (new DownloadRecording($recording->id))->handle();

        $recording->refresh();
        $this->assertSame('downloaded', $recording->status);
        $this->assertSame('recordings/'.$recording->id.'/source.mp4', $recording->source_path);
        $this->assertSame($mp4, Storage::disk('files')->get($recording->source_path));
        $this->assertFileDoesNotExist(storage_path('app/zoom-source-'.$recording->id.'.mp4'));
    }

    public function test_it_rejects_an_html_page_returned_with_a_success_status(): void
    {
        Storage::fake('files');
        $recording = $this->recording();

        Http::fake([
            'zoom.example/*' => Http::response('<!DOCTYPE html><html>login</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        try {
            (new DownloadRecording($recording->id))->handle();
            $this->fail('The HTML download should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Zoom returned a web page instead of the recording.', $exception->getMessage());
        }

        $recording->refresh();
        $this->assertSame('failed', $recording->status);
        $this->assertSame('download', $recording->failed_step);
        Storage::disk('files')->assertMissing('recordings/'.$recording->id.'/source.mp4');
        $this->assertFileDoesNotExist(storage_path('app/zoom-source-'.$recording->id.'.mp4'));
    }

    public function test_it_does_not_call_zoom_without_a_download_token(): void
    {
        Storage::fake('files');
        $recording = $this->recording(['download_token' => null]);
        Http::preventStrayRequests();

        try {
            (new DownloadRecording($recording->id))->handle();
            $this->fail('A missing download token should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Zoom did not provide a download URL and download token.', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame('failed', $recording->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recording(array $attributes = []): ZoomRecording
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

        return ZoomRecording::query()->create([
            'team_id' => $team->id,
            'meeting_uuid' => 'meeting-1',
            'title' => 'Shabbat',
            'status' => 'received',
            'download_url' => 'https://zoom.example/rec/webhook_download/file',
            'download_token' => 'download-secret',
            ...$attributes,
        ]);
    }
}
