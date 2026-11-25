<?php

namespace Tests\Feature\ZoomRecordings;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ZoomRecordings\Actions\LookUpHebcal;
use App\Modules\ZoomRecordings\Jobs\LookUpRecordingDate;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecordingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HebcalTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2-public');
        $this->owner = User::factory()->create();
        $this->team = Team::factory()->create();
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    }

    public function test_a_shabbat_recording_gets_the_default_service_and_the_weekly_portion(): void
    {
        $this->hebcal([['title' => 'Parashat Vayetzei', 'category' => 'parashat']]);
        $recording = $this->recording('2026-11-21 17:22:20');

        app(LookUpHebcal::class)->handle($recording);

        $recording->refresh();
        $this->assertSame('Shabbat Morning Service', $recording->service);
        $this->assertSame('Vayetzei', $recording->torah_portion);
        $this->assertSame('Parashat Vayetzei', $recording->hebcal['items'][0]['title']);
        $this->assertSame('Shabbat Morning Service - Nov 21, 2026', $recording->serviceTitle());
    }

    public function test_a_major_holiday_names_the_morning_service_without_the_year(): void
    {
        $this->hebcal([['title' => 'Rosh Hashana 5787', 'category' => 'holiday', 'subcat' => 'major']]);
        $recording = $this->recording('2026-09-12 15:00:00');

        app(LookUpHebcal::class)->handle($recording);

        $this->assertSame('Rosh Hashana Morning Service', $recording->fresh()->service);
        $this->assertNull($recording->fresh()->torah_portion);
    }

    public function test_an_erev_holiday_is_named_without_morning(): void
    {
        $this->hebcal([['title' => 'Erev Rosh Hashana', 'category' => 'holiday', 'subcat' => 'major']]);
        $recording = $this->recording('2026-09-11 23:00:00');

        app(LookUpHebcal::class)->handle($recording);

        $this->assertSame('Erev Rosh Hashana Service', $recording->fresh()->service);
    }

    public function test_chanukah_on_shabbat_keeps_the_shabbat_service_and_the_portion(): void
    {
        $this->hebcal([
            ['title' => 'Parashat Vayeshev', 'category' => 'parashat'],
            ['title' => 'Mevarchim Chodesh Tevet', 'category' => 'mevarchim'],
            ['title' => 'Chanukah: 2 Candles', 'category' => 'holiday', 'subcat' => 'major'],
        ]);
        $recording = $this->recording('2026-12-05 17:00:00');

        app(LookUpHebcal::class)->handle($recording);

        $this->assertSame('Shabbat Morning Service', $recording->fresh()->service);
        $this->assertSame('Vayeshev', $recording->fresh()->torah_portion);
    }

    public function test_purim_does_not_replace_the_service_name(): void
    {
        $this->assertSame('Shabbat Morning Service', LookUpHebcal::service([
            ['title' => 'Purim', 'category' => 'holiday', 'subcat' => 'major'],
            ['title' => 'Erev Purim', 'category' => 'holiday', 'subcat' => 'major'],
        ]));
        $this->assertSame('Shavuot I Morning Service', LookUpHebcal::service([
            ['title' => 'Shavuot I', 'category' => 'holiday', 'subcat' => 'major'],
        ]));
    }

    public function test_an_evening_service_is_looked_up_on_its_local_date(): void
    {
        $this->hebcal([]);
        $recording = $this->recording('2026-11-22 01:00:00');

        app(LookUpHebcal::class)->handle($recording);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), LookUpHebcal::URL)
            && $request['start'] === '2026-11-21'
            && $request['end'] === '2026-11-21'
            && $request['i'] === 'off');
        $this->assertSame('2026-11-21', $recording->localDate()?->toDateString());
    }

    public function test_the_time_zone_setting_changes_the_local_date(): void
    {
        ZoomRecordingSetting::forTeam($this->team)->update(['timezone' => 'Asia/Jerusalem']);
        $recording = $this->recording('2026-11-21 23:00:00');

        $this->assertSame('2026-11-22', $recording->localDate()?->toDateString());
    }

    public function test_fields_already_filled_in_are_not_overwritten(): void
    {
        $this->hebcal([
            ['title' => 'Parashat Vayetzei', 'category' => 'parashat'],
            ['title' => 'Rosh Hashana 5787', 'category' => 'holiday', 'subcat' => 'major'],
        ]);
        $recording = $this->recording('2026-11-21 17:00:00', ['service' => 'Bar Mitzvah Service', 'torah_portion' => 'Custom']);

        app(LookUpHebcal::class)->handle($recording);

        $this->assertSame('Bar Mitzvah Service', $recording->fresh()->service);
        $this->assertSame('Custom', $recording->fresh()->torah_portion);
        $this->assertNotNull($recording->fresh()->hebcal);
    }

    public function test_a_hebcal_failure_is_noted_and_leaves_the_fields_blank(): void
    {
        Http::fake([LookUpHebcal::URL.'*' => Http::response('nope', 503)]);
        $recording = $this->recording('2026-11-21 17:00:00');

        app(LookUpHebcal::class)->handle($recording);

        $recording->refresh();
        $this->assertNull($recording->service);
        $this->assertNull($recording->hebcal);
        $this->assertSame('Shabbat Morning Service', $recording->serviceLabel());
        $this->assertStringContainsString('Could not look up', collect($recording->log)->last()['message']);
    }

    public function test_a_lookup_on_a_published_recording_updates_the_manifest(): void
    {
        $this->hebcal([['title' => 'Parashat Vayetzei', 'category' => 'parashat']]);
        $recording = $this->recording('2026-11-21 17:00:00', ['hls_path' => 'recordings/1/playlist.m3u8', 'status' => 'completed']);

        app(LookUpHebcal::class)->handle($recording);

        $entry = $this->manifest()[0];
        $this->assertSame('Vayetzei', $entry['torah_portion']);
        $this->assertSame('Shabbat Morning Service', $entry['service']);
        $this->assertSame('Shabbat Morning Service - Nov 21, 2026', $entry['service_title']);
        $this->assertNull($entry['speaker']);
        $this->assertSame('2026-11-21', $entry['date']);
    }

    public function test_the_manifest_uses_the_local_date_for_an_evening_service(): void
    {
        Http::fake([LookUpHebcal::URL.'*' => Http::response(['items' => [['title' => 'Erev Yom Kippur', 'category' => 'holiday', 'subcat' => 'major']]])]);
        $recording = $this->recording('2026-09-21 00:30:00', ['hls_path' => 'recordings/1/playlist.m3u8', 'speaker' => 'Rabbi Levi']);

        app(LookUpHebcal::class)->handle($recording);

        $entry = $this->manifest()[0];
        $this->assertSame('2026-09-20', $entry['date']);
        $this->assertSame('Erev Yom Kippur Service - Sep 20, 2026', $entry['service_title']);
        $this->assertSame('Rabbi Levi', $entry['speaker']);
    }

    public function test_a_new_webhook_recording_is_looked_up_after_the_response(): void
    {
        Bus::fake();
        ZoomRecordingSetting::forTeam($this->team)->update(['webhook_secret' => 'top-secret']);
        $body = json_encode([
            'event' => 'recording.completed',
            'download_token' => 'token',
            'payload' => ['object' => [
                'uuid' => 'meeting-1',
                'topic' => 'Shabbat',
                'start_time' => '2026-11-21T17:22:20Z',
                'recording_files' => [['id' => 'f', 'file_type' => 'MP4', 'recording_type' => 'shared_screen_with_speaker_view', 'download_url' => 'https://zoom.example/d']],
            ]],
        ]);
        $timestamp = (string) time();

        $this->call('POST', route('zoom-recordings.webhook', $this->team->uuid), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_ZM_SIGNATURE' => 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, 'top-secret'),
        ], $body)->assertOk();

        Bus::assertDispatchedAfterResponse(LookUpRecordingDate::class, fn (LookUpRecordingDate $job) => $job->recordingId === ZoomRecording::query()->value('id'));
    }

    public function test_editing_saves_speaker_service_and_portion(): void
    {
        $recording = $this->recording('2026-11-21 17:00:00');

        $this->update($recording, ['title' => 'Vayetzei teaching', 'service' => 'Havdalah Service', 'speaker' => 'Rabbi Levi', 'torah_portion' => 'Vayetzei', 'hidden' => false])
            ->assertRedirect();

        $recording->refresh();
        $this->assertSame('Havdalah Service', $recording->service);
        $this->assertSame('Rabbi Levi', $recording->speaker);
        $this->assertSame('Vayetzei', $recording->torah_portion);
    }

    public function test_clearing_the_service_falls_back_to_the_default(): void
    {
        $recording = $this->recording('2026-11-21 17:00:00', ['service' => 'Havdalah Service', 'speaker' => 'Rabbi Levi']);

        $this->update($recording, ['title' => 'Shabbat', 'service' => '', 'speaker' => '', 'torah_portion' => '', 'hidden' => false])
            ->assertRedirect();

        $recording->refresh();
        $this->assertNull($recording->service);
        $this->assertNull($recording->speaker);
        $this->assertSame('Shabbat Morning Service - Nov 21, 2026', $recording->serviceTitle());
    }

    public function test_the_time_zone_is_saved_and_validated(): void
    {
        $settings = ['r2_prefix' => 'recordings', 'preferred_recording_type' => 'speaker_view'];

        $this->actingAs($this->owner)
            ->put(route('zoom-recordings.settings.update', $this->team->slug), $settings + ['timezone' => 'America/Chicago'])
            ->assertRedirect();
        $this->assertSame('America/Chicago', ZoomRecordingSetting::forTeam($this->team)->timezone);

        $this->actingAs($this->owner)
            ->put(route('zoom-recordings.settings.update', $this->team->slug), $settings + ['timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');
    }

    public function test_new_settings_default_to_new_york(): void
    {
        $this->assertSame('America/New_York', ZoomRecordingSetting::forTeam($this->team)->timezone);
    }

    public function test_the_backfill_command_fills_recordings_without_hebcal_data(): void
    {
        $this->hebcal([['title' => 'Parashat Vayetzei', 'category' => 'parashat']]);
        $missing = $this->recording('2026-11-21 17:00:00');
        $done = $this->recording('2026-11-14 17:00:00', ['hebcal' => ['items' => []], 'service' => 'Kept']);

        $this->artisan('zoom-recordings:hebcal')
            ->expectsOutputToContain('Shabbat Morning Service - Nov 21, 2026')
            ->assertSuccessful();

        $this->assertSame('Vayetzei', $missing->fresh()->torah_portion);
        $this->assertSame('Kept', $done->fresh()->service);
        Http::assertSentCount(1);
    }

    /**
     * @param  list<array<string, string>>  $items
     */
    private function hebcal(array $items): void
    {
        Http::fake([LookUpHebcal::URL.'*' => Http::response(['title' => 'Hebcal', 'items' => $items])]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recording(string $recordedAt, array $attributes = []): ZoomRecording
    {
        return ZoomRecording::query()->create($attributes + [
            'team_id' => $this->team->id,
            'meeting_uuid' => 'meeting-'.uniqid('', true),
            'title' => 'Shabbat',
            'recorded_at' => $recordedAt,
            'status' => 'received',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(ZoomRecording $recording, array $data): TestResponse
    {
        return $this->actingAs($this->owner)->patch(route('zoom-recordings.update', [$this->team->slug, $recording]), $data);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manifest(): array
    {
        return json_decode(Storage::disk('r2-public')->get('recordings/recordings.json'), true)['recordings'];
    }
}
