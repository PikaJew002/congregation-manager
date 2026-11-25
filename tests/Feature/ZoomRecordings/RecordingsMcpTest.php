<?php

namespace Tests\Feature\ZoomRecordings;

use App\Enums\TeamRole;
use App\Mcp\CongregationServer;
use App\Models\Team;
use App\Models\User;
use App\Modules\ZoomRecordings\Jobs\DownloadRecording;
use App\Modules\ZoomRecordings\Jobs\TranscodeAndUpload;
use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Mcp\Tools\ArchiveRecording;
use App\Modules\ZoomRecordings\Mcp\Tools\DeleteRecordingTool;
use App\Modules\ZoomRecordings\Mcp\Tools\GetRecording;
use App\Modules\ZoomRecordings\Mcp\Tools\ListRecordings;
use App\Modules\ZoomRecordings\Mcp\Tools\RestoreRecording;
use App\Modules\ZoomRecordings\Mcp\Tools\RetryRecording;
use App\Modules\ZoomRecordings\Mcp\Tools\UpdateRecordingDetails;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Modules\ZoomRecordings\Models\ZoomRecordingSetting;
use App\Support\Modules\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Mcp\Server\Testing\TestListResponse;
use Tests\TestCase;

class RecordingsMcpTest extends TestCase
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
        ZoomRecordingSetting::forTeam($this->team)->update(['public_base_url' => 'https://cdn.example']);
    }

    public function test_the_congregation_endpoint_requires_a_token(): void
    {
        $this->postJson('/mcp', [])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
    }

    public function test_one_server_lists_slide_and_recording_tools(): void
    {
        $names = $this->toolNames($this->server());

        foreach (['list-recordings', 'get-recording', 'update-recording', 'archive-recording', 'restore-recording', 'retry-recording', 'delete-recording', 'search-library', 'generate-deck'] as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    public function test_recording_tools_are_hidden_when_the_module_is_off(): void
    {
        app(ModuleAccess::class)->setEnabled($this->team, 'zoom-recordings', false);

        $names = $this->toolNames($this->server());

        $this->assertSame([], array_values(array_filter($names, fn (string $name) => str_ends_with($name, '-recording') || $name === 'list-recordings')));
        $this->assertContains('search-library', $names);
    }

    public function test_a_member_without_the_recordings_grant_does_not_get_the_tools(): void
    {
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);
        app(ModuleAccess::class)->grant($this->team, $member, 'service-slides');

        $names = $this->toolNames($this->server($member));

        $this->assertNotContains('list-recordings', $names);
        $this->assertContains('search-library', $names);
    }

    public function test_a_connection_without_an_approved_congregation_gets_no_tools(): void
    {
        $this->assertSame([], $this->toolNames(CongregationServer::actingAs($this->owner)));

        CongregationServer::actingAs($this->owner)->tool(ListRecordings::class)->assertHasErrors();
    }

    public function test_a_connection_approved_for_a_congregation_the_user_left_gets_no_tools(): void
    {
        $other = Team::factory()->create();
        $this->approveMcp($other);

        $this->assertSame([], $this->toolNames(CongregationServer::actingAs($this->owner)));
    }

    public function test_listing_defaults_to_current_recordings_by_date(): void
    {
        $older = $this->recording('2026-11-14', 'Toldot');
        $newer = $this->recording('2026-11-21', 'Vayetzei');
        $this->recording('2026-11-07', 'Chayei Sarah', ['archived_at' => now()]);
        $this->recording('2026-10-31', 'Vayera', ['status' => 'failed', 'failed_step' => 'download', 'hls_path' => null]);

        $this->server()->tool(ListRecordings::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('view', 'current')
                ->has('recordings', 3)
                ->where('recordings.0.id', $newer->id)
                ->where('recordings.0.state', 'published')
                ->where('recordings.1.id', $older->id)
                ->where('recordings.2.state', 'failed')
                ->etc());

        $this->server()->tool(ListRecordings::class, ['view' => 'archived'])
            ->assertOk()
            ->assertSee('Chayei Sarah')
            ->assertDontSee('Vayetzei');

        $this->server()->tool(ListRecordings::class, ['view' => 'failed'])
            ->assertOk()
            ->assertSee('Vayera')
            ->assertDontSee('Toldot');
    }

    public function test_listing_pages_with_a_cursor(): void
    {
        foreach (range(1, 27) as $day) {
            $this->recording(sprintf('2026-10-%02d', $day), 'Day '.$day);
        }

        $this->server()->tool(ListRecordings::class)
            ->assertStructuredContent(fn ($json) => $json->has('recordings', 25)->where('nextCursor', '25')->etc());

        $this->server()->tool(ListRecordings::class, ['cursor' => '25'])
            ->assertStructuredContent(fn ($json) => $json->has('recordings', 2)->missing('nextCursor')->where('recordings.1.title', 'Day 1')->etc());
    }

    public function test_getting_a_recording_shows_links_and_activity(): void
    {
        $recording = $this->recording('2026-11-21', 'Vayetzei', ['speaker' => 'Rabbi Levi', 'torah_portion' => 'Vayetzei']);
        $recording->note('Downloaded source MP4 (360p).');

        $this->server()->tool(GetRecording::class, ['id' => $recording->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('service_title', 'Shabbat Morning Service - Nov 21, 2026')
                ->where('speaker', 'Rabbi Levi')
                ->where('hls', 'https://cdn.example/recordings/'.$recording->id.'/playlist.m3u8')
                ->where('can_delete', true)
                ->where('activity.0.message', 'Downloaded source MP4 (360p).')
                ->etc());
    }

    public function test_another_congregations_recording_is_not_found(): void
    {
        $other = Team::factory()->create();
        $theirs = ZoomRecording::query()->create(['team_id' => $other->id, 'meeting_uuid' => 'x', 'title' => 'Theirs', 'status' => 'completed']);

        $this->server()->tool(GetRecording::class, ['id' => $theirs->id])
            ->assertHasErrors(['No recording with that id']);
        $this->server()->tool(DeleteRecordingTool::class, ['id' => $theirs->id, 'confirm_title' => 'Theirs'])
            ->assertHasErrors(['No recording with that id']);
        $this->assertModelExists($theirs);
    }

    public function test_updating_changes_only_the_fields_sent_and_refreshes_the_feed(): void
    {
        $recording = $this->recording('2026-11-21', 'Vayetzei', ['speaker' => 'Rabbi Levi', 'torah_portion' => 'Vayetzei', 'service' => 'Havdalah Service']);

        $this->server()->tool(UpdateRecordingDetails::class, ['id' => $recording->id, 'title' => 'Jacob at Bethel', 'speaker' => ''])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('title', 'Jacob at Bethel')->where('speaker', null)->where('torah_portion', 'Vayetzei')->where('service', 'Havdalah Service')->etc());

        $this->server()->tool(UpdateRecordingDetails::class, ['id' => $recording->id, 'service' => ''])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('service', 'Shabbat Morning Service')->etc());

        $this->assertSame('Jacob at Bethel', $this->feed()[0]['title']);
    }

    public function test_updating_needs_at_least_one_field(): void
    {
        $recording = $this->recording('2026-11-21', 'Vayetzei');

        $this->server()->tool(UpdateRecordingDetails::class, ['id' => $recording->id])
            ->assertHasErrors(['Send at least one of']);
    }

    public function test_archive_and_restore_move_a_recording_off_and_back_onto_the_feed(): void
    {
        $recording = $this->recording('2026-11-21', 'Vayetzei');
        $this->travelTo('2026-11-25 13:00:00');

        $this->server()->tool(ArchiveRecording::class, ['id' => $recording->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('state', 'archived')->etc());
        $this->assertSame([], $this->feed());

        $this->travelTo('2026-11-26 09:00:00');
        $this->server()->tool(ArchiveRecording::class, ['id' => $recording->id])->assertOk();
        $this->assertSame('2026-11-25 13:00:00', $recording->fresh()->archived_at->toDateTimeString());

        $this->server()->tool(RestoreRecording::class, ['id' => $recording->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('state', 'published')->where('archived_at', null)->etc());
        $this->assertSame([$recording->id], array_column($this->feed(), 'id'));
    }

    public function test_retry_reruns_a_failed_recording_from_its_failed_step(): void
    {
        Bus::fake();
        config(['services.media_worker.url' => null]);
        $failed = $this->recording('2026-11-21', 'Vayetzei', ['status' => 'failed', 'failed_step' => 'download', 'hls_path' => null]);

        $this->server()->tool(RetryRecording::class, ['id' => $failed->id])->assertOk();

        Bus::assertChained([DownloadRecording::class, TranscodeAndUpload::class, UpdateManifest::class]);
    }

    public function test_retry_refuses_a_recording_that_has_not_failed(): void
    {
        $recording = $this->recording('2026-11-21', 'Vayetzei');

        $this->server()->tool(RetryRecording::class, ['id' => $recording->id])
            ->assertHasErrors(['Only failed recordings can be retried. This one is published.']);
    }

    public function test_delete_needs_the_exact_title(): void
    {
        $recording = $this->recording('2026-11-21', 'Vayetzei');
        $this->files($recording);

        $this->server()->tool(DeleteRecordingTool::class, ['id' => $recording->id, 'confirm_title' => 'vayetzei '])
            ->assertHasErrors(['confirm_title does not match']);

        $this->assertModelExists($recording);
        Storage::disk('files')->assertExists('recordings/'.$recording->id.'/source.mp4');
    }

    public function test_delete_refuses_a_recording_that_is_still_processing(): void
    {
        $recording = $this->recording('2026-11-21', 'Vayetzei', ['status' => 'transcoding', 'hls_path' => null]);

        $this->server()->tool(DeleteRecordingTool::class, ['id' => $recording->id, 'confirm_title' => 'Vayetzei'])
            ->assertHasErrors(['still processing']);

        $this->assertModelExists($recording);
    }

    public function test_delete_removes_the_recording_its_files_and_its_feed_entry(): void
    {
        $keep = $this->recording('2026-11-14', 'Toldot');
        $gone = $this->recording('2026-11-21', 'Vayetzei');
        $this->files($gone);

        $this->server()->tool(DeleteRecordingTool::class, ['id' => $gone->id, 'confirm_title' => 'Vayetzei'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('deleted', true)->where('id', $gone->id)->etc());

        $this->assertModelMissing($gone);
        $this->assertSame([], Storage::disk('r2-public')->allFiles('recordings/'.$gone->id));
        $this->assertSame([], Storage::disk('files')->allFiles('recordings/'.$gone->id));
        $this->assertSame([$keep->id], array_column($this->feed(), 'id'));
    }

    /**
     * Names from tools/list. The package's assertRegistered() builds each
     * tool with `new`, which these constructor-injected tools do not allow.
     *
     * @return list<string>
     */
    private function toolNames(PendingTestResponse $server): array
    {
        $items = (new \ReflectionProperty(TestListResponse::class, 'items'))->getValue($server->tools());

        return array_column($items, 'name');
    }

    private function server(?User $user = null): PendingTestResponse
    {
        $this->approveMcp($this->team);

        return CongregationServer::actingAs($user ?? $this->owner);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recording(string $date, string $title, array $attributes = []): ZoomRecording
    {
        $recording = ZoomRecording::query()->create($attributes + [
            'team_id' => $this->team->id,
            'meeting_uuid' => 'meeting-'.uniqid('', true),
            'title' => $title,
            'recorded_at' => $date.' 17:00:00',
            'status' => 'completed',
            'hls_path' => 'placeholder',
        ]);

        if ($recording->hls_path === 'placeholder') {
            $recording->update(['hls_path' => $recording->directory().'/playlist.m3u8']);
        }

        if ($recording->hls_path && ! $recording->isArchived()) {
            (new UpdateManifest($recording->id))->handle();
        }

        return $recording->fresh();
    }

    private function files(ZoomRecording $recording): void
    {
        foreach (['playlist.m3u8', '360p/segment_000.ts', 'poster.jpg', 'source.mp4'] as $file) {
            Storage::disk('r2-public')->put($recording->directory().'/'.$file, 'x');
        }
        Storage::disk('files')->put($recording->directory().'/source.mp4', 'x');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function feed(): array
    {
        return json_decode(Storage::disk('r2-public')->get('recordings/recordings.json'), true)['recordings'];
    }
}
