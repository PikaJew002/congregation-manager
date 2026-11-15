<?php

namespace Tests\Feature\ZoomRecordings;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ZoomRecordings\Jobs\TranscodeAndUpload;
use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecordingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ZoomRecordingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_both_pages_without_seeing_the_download_token(): void
    {
        config(['inertia.testing.ensure_pages_exist' => false]);
        [$team, $owner] = $this->owner();
        ZoomRecordingSetting::forTeam($team)->update(['webhook_secret' => 'zz-hidden-secret']);
        $this->recording($team, ['download_token' => 'zoom-token']);

        foreach (['Index', 'Settings'] as $component) {
            $this->assertFileExists(resource_path("js/modules/zoom-recordings/pages/{$component}.vue"));
        }

        $this->actingAs($owner)
            ->get(route('zoom-recordings.index', $team->slug))
            ->assertOk()
            ->assertDontSee('zoom-token')
            ->assertInertia(fn (Assert $page) => $page->component('zoom-recordings/Index')
                ->has('recordings', 1)
                ->missing('recordings.0.download_token')
                ->has('publicBaseUrl'));

        $this->actingAs($owner)
            ->get(route('zoom-recordings.settings', $team->slug))
            ->assertOk()
            ->assertDontSee('zz-hidden-secret')
            ->assertInertia(fn (Assert $page) => $page->component('zoom-recordings/Settings')
                ->where('settings.has_webhook_secret', true)
                ->missing('settings.webhook_secret'));
    }

    public function test_saving_settings_keeps_secrets_left_blank(): void
    {
        [$team, $owner] = $this->owner();
        ZoomRecordingSetting::forTeam($team)->update(['webhook_secret' => 'keep-me']);

        $this->actingAs($owner)
            ->put(route('zoom-recordings.settings.update', $team->slug), [
                'webhook_secret' => '',
                'clear_webhook_secret' => false,
                'r2_prefix' => 'sermons',
                'public_base_url' => 'https://media.example.com',
                'preferred_recording_type' => 'speaker_view',
            ])
            ->assertRedirect();

        $settings = ZoomRecordingSetting::forTeam($team)->fresh();
        $this->assertSame('keep-me', $settings->webhook_secret);
        $this->assertSame('sermons', $settings->r2_prefix);
    }

    public function test_saving_settings_can_clear_the_webhook_secret(): void
    {
        [$team, $owner] = $this->owner();
        ZoomRecordingSetting::forTeam($team)->update(['webhook_secret' => 'remove-me']);

        $this->actingAs($owner)
            ->put(route('zoom-recordings.settings.update', $team->slug), [
                'webhook_secret' => '',
                'clear_webhook_secret' => true,
                'r2_prefix' => 'sermons',
                'public_base_url' => 'https://media.example.com',
                'preferred_recording_type' => 'speaker_view',
            ])
            ->assertRedirect();

        $this->assertNull(ZoomRecordingSetting::forTeam($team)->fresh()->webhook_secret);
    }

    public function test_editing_an_unpublished_recording_does_not_touch_the_manifest(): void
    {
        Storage::fake('r2-public');
        [$team, $owner] = $this->owner();
        $recording = $this->recording($team, ['status' => 'transcoding']);

        $this->actingAs($owner)
            ->patch(route('zoom-recordings.update', [$team->slug, $recording]), ['title' => 'Sunday', 'hidden' => false])
            ->assertRedirect();

        $this->assertSame('Sunday', $recording->fresh()->title);
        $this->assertSame('transcoding', $recording->fresh()->status);
        Storage::disk('r2-public')->assertMissing('recordings/recordings.json');
    }

    public function test_editing_a_completed_recording_updates_the_manifest_and_keeps_its_status(): void
    {
        Storage::fake('r2-public');
        [$team, $owner] = $this->owner();
        $recording = $this->recording($team, ['status' => 'completed', 'hls_path' => 'recordings/1/playlist.m3u8']);

        $this->actingAs($owner)
            ->patch(route('zoom-recordings.update', [$team->slug, $recording]), ['title' => 'Sunday', 'hidden' => false])
            ->assertRedirect();

        $this->assertSame('completed', $recording->fresh()->status);
        Storage::disk('r2-public')->assertExists('recordings/recordings.json');
    }

    public function test_only_failed_recordings_can_be_retried(): void
    {
        Bus::fake();
        [$team, $owner] = $this->owner();
        $done = $this->recording($team, ['status' => 'completed']);
        $failed = $this->recording($team, ['meeting_uuid' => 'other', 'status' => 'failed', 'failed_step' => 'transcode']);

        $this->actingAs($owner)
            ->post(route('zoom-recordings.retry', [$team->slug, $done]))
            ->assertStatus(422);

        $this->actingAs($owner)
            ->post(route('zoom-recordings.retry', [$team->slug, $failed]))
            ->assertRedirect();

        Bus::assertChained([
            TranscodeAndUpload::class,
            UpdateManifest::class,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recording(Team $team, array $attributes = []): ZoomRecording
    {
        return ZoomRecording::query()->create([
            'team_id' => $team->id,
            'meeting_uuid' => 'abc',
            'title' => 'Recording',
            'status' => 'received',
            ...$attributes,
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
        $user->switchTeam($team);

        return [$team, $user];
    }
}
