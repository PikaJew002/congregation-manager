<?php

namespace Tests\Feature\ServiceSlides;

use App\Enums\TeamRole;
use App\Mcp\CongregationServer;
use App\Models\Team;
use App\Models\TeamModule;
use App\Models\User;
use App\Modules\ServiceSlides\Actions\ServicePlanner;
use App\Modules\ServiceSlides\Mcp\Tools\GetLibraryFile;
use App\Modules\ServiceSlides\Mcp\Tools\GetService;
use App\Modules\ServiceSlides\Models\ServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\OdpInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Tests\TestCase;

class SlideFileDownloadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    public function test_the_library_downloads_the_formatted_or_original_file_named_after_the_title(): void
    {
        config(['inertia.testing.ensure_pages_exist' => false]);
        [$team, $owner] = $this->owner();
        $song = $this->song($team, 'Come, Thou Fount: Verse 1/2');

        $this->actingAs($owner)
            ->get(route('service-slides.library.download', [$team->slug, $song]))
            ->assertOk()
            ->assertDownload('Come, Thou Fount- Verse 1-2.odp')
            ->assertStreamedContent('formatted');

        $this->actingAs($owner)
            ->get(route('service-slides.library.download', [$team->slug, $song, 'file' => 'original']))
            ->assertOk()
            ->assertStreamedContent('original');

        $this->actingAs($owner)
            ->get(route('service-slides.library', $team->slug))
            ->assertInertia(fn (Assert $page) => $page->where('sources.0.has_original_file', fn ($value) => (bool) $value));
    }

    public function test_the_library_download_refuses_other_teams_missing_files_and_unknown_choices(): void
    {
        [$team, $owner] = $this->owner();
        [$other] = $this->owner();
        $theirs = $this->song($other, 'Theirs');
        $missing = $this->song($team, 'Missing');
        Storage::disk('files')->delete($missing->file_path);

        $this->actingAs($owner)->get(route('service-slides.library.download', [$team->slug, $theirs]))->assertNotFound();
        $this->actingAs($owner)->get(route('service-slides.library.download', [$team->slug, $missing]))->assertNotFound();
        $this->actingAs($owner)
            ->getJson(route('service-slides.library.download', [$team->slug, $this->song($team, 'Mine'), 'file' => 'notes']))
            ->assertUnprocessable();
    }

    public function test_get_library_file_returns_a_signed_link_that_downloads_once_and_logs_it(): void
    {
        [$team, $owner] = $this->owner();
        $song = $this->song($team, 'Kumi Ori');
        Log::spy();

        $link = $this->libraryLink($owner, $team, $song->id, 'original');

        $this->assertSame(['Kumi Ori', 'odp', 'original', 'Kumi Ori.odp'], [$link['title'], $link['format'], $link['file'], $link['fileName']]);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), strtotime($link['expiresAt']), 5);

        $this->get($link['url'])->assertOk()->assertDownload('Kumi Ori.odp')->assertStreamedContent('original');

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => str_contains($message, 'signed link')
            && $context === ['user_id' => $owner->id, 'team_id' => $team->id, 'kind' => 'library', 'id' => $song->id, 'file' => 'original'])->once();
    }

    public function test_a_signed_link_is_refused_once_expired_or_edited(): void
    {
        [$team, $owner] = $this->owner();
        $song = $this->song($team, 'Kumi Ori');
        $other = $this->song($team, 'Other');
        $url = $this->libraryLink($owner, $team, $song->id)['url'];

        $this->get(str_replace('/library/'.$song->id, '/library/'.$other->id, $url))->assertForbidden();
        $this->get(str_replace('file=formatted', 'file=original', $url))->assertForbidden();
        $this->get(preg_replace('/([?&]team=)\d+/', '${1}999', $url))->assertForbidden();

        $this->travel(11)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_a_signed_link_stops_working_when_access_is_lost(): void
    {
        [$team, $owner] = $this->owner();
        $song = $this->song($team, 'Kumi Ori');
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $url = $this->libraryLink($owner, $team, $song->id)['url'];

        // Removed from the congregation.
        $team->members()->detach($owner);
        $this->get($url)->assertForbidden();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        $this->get($url)->assertOk();

        // Service slides turned off for the congregation.
        TeamModule::query()->create(['team_id' => $team->id, 'module_key' => 'service-slides', 'enabled' => false]);
        $this->get($url)->assertForbidden();
        TeamModule::query()->where('team_id', $team->id)->delete();

        // The item now belongs to another congregation.
        [$elsewhere] = $this->owner();
        $song->update(['team_id' => $elsewhere->id]);
        $this->get($url)->assertNotFound();
    }

    public function test_get_library_file_rejects_items_outside_the_congregation(): void
    {
        [$team, $owner] = $this->owner();
        [$other] = $this->owner();
        $theirs = $this->song($other, 'Theirs');
        $this->approveMcp($team);

        CongregationServer::actingAs($owner)
            ->tool(GetLibraryFile::class, ['id' => $theirs->id])
            ->assertHasErrors(['No library item with that id in this congregation.']);
        CongregationServer::actingAs($owner)
            ->tool(GetLibraryFile::class, ['id' => $theirs->id, 'file' => 'notes'])
            ->assertHasErrors();
    }

    public function test_get_service_gives_a_link_for_each_generated_deck(): void
    {
        [$team, $owner] = $this->owner();
        $this->template($team);
        $this->song($team, 'Kumi Ori');
        $result = app(ServicePlanner::class)->upsert($team, '2026-11-28', 'Music', ['Kumi Ori']);
        /** @var ServiceDeck $deck */
        $deck = $result['deck'];
        $this->approveMcp($team);

        $this->assertArrayNotHasKey('downloadUrl', $this->service($owner)['decks'][0]);

        Storage::disk('files')->put('decks/music.odp', 'deck');
        $deck->update(['generated_path' => 'decks/music.odp', 'status' => 'ready']);

        $found = $this->service($owner)['decks'][0];
        $this->assertArrayHasKey('expiresAt', $found);
        $this->get($found['downloadUrl'])->assertOk()->assertDownload('26-11-28 Music.odp')->assertStreamedContent('deck');

        $deck->update(['status' => 'generating']);
        $this->get($found['downloadUrl'])->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function libraryLink(User $owner, Team $team, int $id, ?string $file = null): array
    {
        $this->approveMcp($team);

        return $this->decoded(CongregationServer::actingAs($owner)->tool(GetLibraryFile::class, array_filter(['id' => $id, 'file' => $file]))->assertOk());
    }

    /**
     * @return array<string, mixed>
     */
    private function service(User $owner): array
    {
        return $this->decoded(CongregationServer::actingAs($owner)->tool(GetService::class, ['date' => '2026-11-28'])->assertOk());
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(object $response): array
    {
        $content = (new ReflectionMethod($response, 'content'))->invoke($response);

        return json_decode($content[0], true);
    }

    /**
     * A song whose formatted file and original upload differ, as after song formatting.
     */
    private function song(Team $team, string $title): SlideSource
    {
        $key = md5($team->id.$title);
        Storage::disk('files')->put("slides/{$key}-formatted.odp", 'formatted');
        Storage::disk('files')->put("slides/{$key}-original.odp", 'original');

        return SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'song',
            'title' => $title,
            'original_path' => "slides/{$key}-original.odp",
            'original_format' => 'odp',
            'file_path' => "slides/{$key}-formatted.odp",
            'slide_count' => 1,
        ]);
    }

    private function template(Team $team): SlideTemplate
    {
        $local = base_path('tests/Fixtures/slides/music-template.odp');
        Storage::disk('files')->put('templates/music.odp', (string) file_get_contents($local));
        $inspector = app(OdpInspector::class);
        $template = SlideTemplate::query()->create([
            'team_id' => $team->id,
            'name' => 'Music',
            'file_path' => 'templates/music.odp',
            'page_size' => $inspector->pageSize($local),
            'default_kind' => 'song',
            'is_history_tracked' => true,
        ]);

        foreach ($inspector->slides($local) as $slide) {
            $template->slides()->create([
                'slide_index' => $slide['index'],
                'label' => $slide['label'],
                'role' => $slide['empty'] ? 'separator' : ($slide['index'] === 0 ? 'intro' : 'title'),
            ]);
        }

        return $template;
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
