<?php

namespace Tests\Feature\ZoomRecordings;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ZoomRecordings\Jobs\DownloadRecording;
use App\Modules\ZoomRecordings\Jobs\TranscodeAndUpload;
use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecordingSetting;
use App\Support\Modules\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ZoomWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_answers_zoom_url_validation(): void
    {
        $team = $this->team();
        ZoomRecordingSetting::forTeam($team)->update(['webhook_secret' => 'top-secret']);

        $response = $this->postJson(route('zoom-recordings.webhook', $team->uuid), [
            'event' => 'endpoint.url_validation',
            'payload' => ['plainToken' => 'plain'],
        ]);

        $response->assertOk();
        $response->assertJson([
            'plainToken' => 'plain',
            'encryptedToken' => hash_hmac('sha256', 'plain', 'top-secret'),
        ]);
    }

    public function test_it_refuses_events_until_a_secret_is_saved(): void
    {
        $team = $this->team();
        $body = json_encode(['event' => 'recording.completed']);
        $timestamp = (string) time();

        $this->call('POST', route('zoom-recordings.webhook', $team->uuid), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_ZM_SIGNATURE' => 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, ''),
        ], $body)->assertForbidden();
    }

    public function test_it_rejects_a_bad_signature(): void
    {
        $team = $this->team();
        ZoomRecordingSetting::forTeam($team)->update(['webhook_secret' => 'top-secret']);

        $this->postJson(route('zoom-recordings.webhook', $team->uuid), [
            'event' => 'recording.completed',
        ], ['x-zm-signature' => 'v0=nope', 'x-zm-request-timestamp' => (string) time()])
            ->assertUnauthorized();
    }

    public function test_a_completed_recording_is_stored_once_and_queued(): void
    {
        Bus::fake();
        $team = $this->team();
        ZoomRecordingSetting::forTeam($team)->update(['webhook_secret' => 'top-secret']);
        $payload = [
            'event' => 'recording.completed',
            'download_token' => 'token',
            'payload' => [
                'object' => [
                    'uuid' => 'meeting-1',
                    'id' => 99,
                    'topic' => 'Shabbat',
                    'start_time' => '2026-11-01T14:00:00Z',
                    'duration' => 80,
                    'recording_files' => [[
                        'id' => 'file-1',
                        'file_type' => 'MP4',
                        'recording_type' => 'shared_screen_with_speaker_view',
                        'download_url' => 'https://zoom.example/download',
                    ]],
                ],
            ],
        ];

        $this->signed($team, $payload)->assertOk();
        $this->signed($team, $payload)->assertOk();

        $this->assertDatabaseCount('zoom_recordings', 1);
        $recording = ZoomRecording::query()->firstOrFail();
        $this->assertSame('https://zoom.example/download', $recording->download_url);
        $this->assertSame('token', $recording->download_token);
        Bus::assertChained([
            DownloadRecording::class,
            TranscodeAndUpload::class,
            UpdateManifest::class,
        ]);
    }

    public function test_a_second_event_refreshes_the_download_link_before_the_file_is_stored(): void
    {
        Bus::fake();
        $team = $this->team();
        ZoomRecordingSetting::forTeam($team)->update(['webhook_secret' => 'top-secret']);
        $payload = [
            'event' => 'recording.completed',
            'download_token' => 'first-token',
            'payload' => [
                'object' => [
                    'uuid' => 'meeting-1',
                    'topic' => 'Shabbat',
                    'recording_files' => [[
                        'id' => 'file-1',
                        'file_type' => 'MP4',
                        'recording_type' => 'shared_screen_with_speaker_view',
                        'download_url' => 'https://zoom.example/first',
                    ]],
                ],
            ],
        ];

        $this->signed($team, $payload)->assertOk();
        $payload['download_token'] = 'second-token';
        $payload['payload']['object']['recording_files'][0]['download_url'] = 'https://zoom.example/second';
        $this->signed($team, $payload)->assertOk();

        $recording = ZoomRecording::query()->firstOrFail();
        $this->assertSame('https://zoom.example/second', $recording->download_url);
        $this->assertSame('second-token', $recording->download_token);
        Bus::assertDispatchedTimes(DownloadRecording::class, 1);
    }

    public function test_a_disabled_module_hides_the_webhook(): void
    {
        $team = $this->team();
        app(ModuleAccess::class)->setEnabled($team, 'zoom-recordings', false);

        $this->postJson(route('zoom-recordings.webhook', $team->uuid), [])->assertNotFound();
    }

    private function team(): Team
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

        return $team;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signed(Team $team, array $payload): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, 'top-secret');

        return $this->call('POST', route('zoom-recordings.webhook', $team->uuid), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_ZM_SIGNATURE' => $signature,
        ], $body);
    }
}
