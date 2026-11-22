<?php

namespace Tests\Feature\ServiceSlides;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Actions\ServicePlanner;
use App\Modules\ServiceSlides\Http\Controllers\DeckController;
use App\Modules\ServiceSlides\Jobs\GenerateServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\OdpInspector;
use App\Modules\ServiceSlides\Support\PptxInspector;
use App\Support\Modules\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ServiceSlidesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_files_that_are_not_presentations_are_rejected(): void
    {
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), [
                'title' => 'Psalm',
                'kind' => 'liturgy',
                'file' => UploadedFile::fake()->create('psalm.pptx', 20, PptxInspector::MIMETYPE),
            ])
            ->assertSessionHasErrors(['file' => 'Upload an ODP or PPTX presentation.']);

        $legacy = tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($legacy, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 512));

        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), [
                'title' => 'Psalm',
                'kind' => 'liturgy',
                'file' => new UploadedFile($legacy, 'psalm.ppt', 'application/vnd.ms-powerpoint', null, true),
            ])
            ->assertSessionHasErrors(['file' => 'This is an old PowerPoint file (.ppt). Save it as PPTX first.']);
    }

    public function test_powerpoint_liturgy_is_stored_as_uploaded_and_replaced_only_with_powerpoint(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), ['title' => 'Kaddish', 'kind' => 'liturgy', 'file' => $this->upload('kaddish.pptx')])
            ->assertSessionHasNoErrors();

        $source = SlideSource::query()->where('title', 'Kaddish')->firstOrFail();
        $this->assertSame('pptx', $source->original_format);
        $this->assertSame($source->original_path, $source->file_path);
        $this->assertStringEndsWith('.pptx', $source->file_path);
        $this->assertSame(3, $source->slide_count);
        $this->assertStringStartsWith('Mourner’s Kaddish', $source->slide_lines[0]);
        $this->assertSame('33.867cm x 19.05cm', $source->page_size);

        $this->actingAs($owner)
            ->post(route('service-slides.library.file', [$team, $source]), ['file' => $this->upload('psalm-19.odp')])
            ->assertSessionHasErrors(['file' => 'Liturgy must be PPTX files.']);

        $this->actingAs($owner)
            ->post(route('service-slides.library.file', [$team, $source]), ['file' => $this->upload('adon-olam.pptx')])
            ->assertSessionHasNoErrors();
        $this->assertStringStartsWith('Adon Olam', $source->fresh()->slide_lines[0]);
    }

    public function test_the_same_title_can_be_a_song_and_a_liturgy_item(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), ['title' => 'Adon Olam', 'kind' => 'song', 'file' => $this->upload('kumi-ori.odp')])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), ['title' => 'Adon Olam', 'kind' => 'liturgy', 'file' => $this->upload('adon-olam.pptx')])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), ['title' => 'Adon Olam', 'kind' => 'song', 'file' => $this->upload('kumi-ori.odp')])
            ->assertSessionHasErrors(['title' => 'Adon Olam is already in this congregation\'s library as song.']);

        $this->assertSame(2, SlideSource::query()->where('team_id', $team->id)->where('title', 'Adon Olam')->count());

        $song = SlideSource::query()->where('team_id', $team->id)->where('kind', 'song')->firstOrFail();
        $this->actingAs($owner)
            ->put(route('service-slides.library.update', [$team, $song]), [
                'title' => 'Adon Olam',
                'kind' => 'liturgy',
            ])
            ->assertSessionHasErrors(['title' => 'Adon Olam is already in this congregation\'s library as liturgy.']);

        $this->actingAs($owner)
            ->delete(route('service-slides.library.destroy', [$team, $song]))
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), ['title' => 'Adon Olam', 'kind' => 'song', 'file' => $this->upload('kumi-ori.odp')])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post(route('service-slides.library.restore', [$team, $song]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($song->fresh()->archived);
    }

    public function test_a_shared_title_matches_the_requested_kind_before_a_close_name(): void
    {
        [$team] = $this->owner();
        SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'song',
            'title' => 'Adon Olahm',
            'original_format' => 'odp',
            'original_path' => 'slides/fuzzy.odp',
            'file_path' => 'slides/fuzzy.odp',
            'slide_count' => 1,
        ]);
        $other = SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'other',
            'title' => 'Adon Olam',
            'original_format' => 'odp',
            'original_path' => 'slides/other.odp',
            'file_path' => 'slides/other.odp',
            'slide_count' => 1,
        ]);

        $this->assertSame($other->id, app(ServicePlanner::class)->match($team, 'Adon Olam', 'odp', 'song')?->id);

        $song = SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'song',
            'title' => 'Adon Olam',
            'original_format' => 'odp',
            'original_path' => 'slides/song.odp',
            'file_path' => 'slides/song.odp',
            'slide_count' => 1,
        ]);

        $this->assertSame($song->id, app(ServicePlanner::class)->match($team, 'Adon Olam', 'odp', 'song')?->id);
        $this->assertSame($other->id, app(ServicePlanner::class)->match($team, 'Adon Olam', 'odp', 'other')?->id);
    }

    public function test_a_powerpoint_template_builds_a_powerpoint_deck_from_powerpoint_items_only(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('service-slides.templates.store', $team), ['name' => 'Liturgy', 'default_kind' => 'liturgy', 'file' => $this->upload('liturgy-service.pptx')])
            ->assertSessionHasNoErrors();
        $template = SlideTemplate::query()->where('name', 'Liturgy')->firstOrFail();
        $this->assertSame('pptx', $template->format);
        $this->assertCount(55, $template->slides);

        $slides = $template->slides->keyBy('slide_index');
        $template->update(['preset' => [
            ['type' => 'template_slide', 'slide_id' => $slides[0]->id],
            ['type' => 'slot', 'key' => 'items', 'label' => 'Items', 'kind' => 'liturgy', 'repeat' => true, 'separator_slide_id' => null, 'default_source_ids' => []],
            ['type' => 'template_slide', 'slide_id' => $slides[54]->id],
        ]]);

        $odpBarchu = $this->source($team, 'psalm-19.odp', 'Barchu');
        foreach (['barchu.pptx' => 'Barchu', 'modim.pptx' => 'Modim', 'adon-olam.pptx' => 'Adon Olam'] as $fixture => $title) {
            $this->actingAs($owner)
                ->post(route('service-slides.library.store', $team), ['title' => $title, 'kind' => 'liturgy', 'file' => $this->upload($fixture)])
                ->assertSessionHasNoErrors();
        }

        $result = app(ServicePlanner::class)->upsert($team, '2026-11-14', 'Liturgy', ['Barchu', 'Modim', 'Adon Olam', 'Kumi Ori']);
        $this->assertSame(['items' => ['Kumi Ori']], $result['unmatched']);

        $deck = $result['deck'];
        $this->assertFalse($deck->entries()->where('slide_source_id', $odpBarchu->id)->exists());

        GenerateServiceDeck::dispatchSync($deck->id);
        $deck->refresh();
        $this->assertSame('ready', $deck->status);
        $this->assertSame([], $deck->warnings);
        $this->assertStringEndsWith('.pptx', $deck->generated_path);

        $local = tempnam(sys_get_temp_dir(), 'deck-');
        file_put_contents($local, Storage::disk('files')->get($deck->generated_path));
        $lines = array_map(fn (array $slide) => $slide['line'], app(PptxInspector::class)->slides($local));
        @unlink($local);

        $this->assertCount(10, $lines);
        $this->assertStringStartsWith('Psalm 29', $lines[0]);
        $this->assertStringStartsWith('Barchu', $lines[1]);
        $this->assertStringStartsWith('Modim', $lines[3]);
        $this->assertStringStartsWith('Adon Olam', $lines[6]);
        $this->assertNull($lines[9]);

        $this->actingAs($owner)
            ->get(route('service-slides.decks.download', [$team, $deck]))
            ->assertOk()
            ->assertDownload('26-11-14 Liturgy.pptx');

        Queue::fake();

        $this->actingAs($owner)
            ->post(route('service-slides.decks.generate', [$team, $deck]))
            ->assertRedirect()
            ->assertInertiaFlash('deck', $deck->id);

        $this->actingAs($owner)
            ->put(route('service-slides.decks.entries', [$team, $deck]), ['entries' => [
                ['entry_type' => 'source', 'slide_source_id' => $odpBarchu->id],
            ]])
            ->assertSessionHasErrors(['entries.0.slide_source_id' => 'This deck uses PPTX files, so every library item in it must be PPTX.']);
    }

    public function test_uploaded_songs_are_formatted_and_keep_the_original(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), ['title' => 'Kumi Ori', 'kind' => 'song', 'file' => $this->upload('kumi-ori.odp')])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), ['title' => 'Psalm 19', 'kind' => 'liturgy', 'file' => $this->upload('psalm-19.odp')])
            ->assertSessionHasErrors(['file' => 'Liturgy must be PPTX files.']);
        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), ['title' => 'Kaddish', 'kind' => 'liturgy', 'file' => $this->upload('kaddish.pptx')])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), ['title' => 'Song as PPTX', 'kind' => 'song', 'file' => $this->upload('barchu.pptx')])
            ->assertSessionHasErrors(['file' => 'Songs must be ODP files.']);

        $song = SlideSource::query()->where('title', 'Kumi Ori')->firstOrFail();
        $this->assertNotSame($song->original_path, $song->file_path);
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/slides/kumi-ori.odp')), Storage::disk('files')->get($song->original_path));
        $this->assertNotSame(Storage::disk('files')->get($song->original_path), Storage::disk('files')->get($song->file_path));

        $liturgy = SlideSource::query()->where('title', 'Kaddish')->firstOrFail();
        $this->assertSame($liturgy->original_path, $liturgy->file_path);

        $formatted = $song->file_path;
        $this->actingAs($owner)
            ->put(route('service-slides.library.update', [$team, $song]), ['title' => 'Kumi Ori', 'kind' => 'other'])
            ->assertSessionHasNoErrors();
        $this->assertSame($song->original_path, $song->fresh()->file_path);
        Storage::disk('files')->assertMissing($formatted);
    }

    public function test_a_dropbox_or_drive_link_can_add_library_and_template_files(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();
        $odp = (string) file_get_contents(base_path('tests/Fixtures/slides/kumi-ori.odp'));
        $pptx = (string) file_get_contents(base_path('tests/Fixtures/slides/kaddish.pptx'));
        $requested = [];

        Http::fake(function ($request) use ($odp, $pptx, &$requested) {
            $requested[] = $request->url();

            if (str_contains($request->url(), 'drive.google.com/uc')) {
                return Http::response($pptx, 200, [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Disposition' => 'attachment; filename="Kaddish.pptx"',
                ]);
            }

            return Http::response($odp, 200, ['Content-Type' => 'application/octet-stream']);
        });

        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), [
                'title' => 'From Dropbox',
                'kind' => 'song',
                'url' => 'https://www.dropbox.com/scl/fi/abc/kumi-ori.odp?rlkey=secret&st=token&dl=0',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), [
                'title' => 'From Drive',
                'kind' => 'liturgy',
                'url' => 'https://docs.google.com/presentation/d/1Ug67oy8xtZl-4sEj5a4Db6-PNw9tikmc/edit?usp=sharing',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post(route('service-slides.templates.store', $team), [
                'name' => 'Liturgy from Drive',
                'default_kind' => 'liturgy',
                'url' => 'https://drive.google.com/file/d/1aOlcIaIVhPZBiJoOiePH1ralnX-Xh1wo/view',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(collect($requested)->contains(fn (string $url) => str_contains($url, 'dl=1') && ! str_contains($url, 'st=')));
        $this->assertTrue(collect($requested)->contains('https://drive.google.com/uc?export=download&id=1Ug67oy8xtZl-4sEj5a4Db6-PNw9tikmc'));
        $this->assertTrue(collect($requested)->contains('https://drive.google.com/uc?export=download&id=1aOlcIaIVhPZBiJoOiePH1ralnX-Xh1wo'));
        $this->assertSame('odp', SlideSource::query()->where('title', 'From Dropbox')->value('original_format'));
        $this->assertSame('pptx', SlideSource::query()->where('title', 'From Drive')->value('original_format'));
        $this->assertSame('pptx', SlideTemplate::query()->where('name', 'Liturgy from Drive')->value('format'));
    }

    public function test_the_format_command_formats_songs_added_before_import_formatting(): void
    {
        Storage::fake('files');
        [$team] = $this->owner();
        $song = $this->source($team, 'trees-of-the-field.odp', 'Trees of the Field');

        $this->artisan('service-slides:format-songs')->assertSuccessful();

        $song->refresh();
        $this->assertNotSame($song->original_path, $song->file_path);
        Storage::disk('files')->assertExists($song->original_path);
        Storage::disk('files')->assertExists($song->file_path);
    }

    public function test_a_deck_merges_template_slides_and_library_files(): void
    {
        Storage::fake('files');
        [$team] = $this->owner();
        $template = $this->template($team, 'music-template.odp', 'Music', true);
        $this->source($team, 'kumi-ori.odp', 'Kumi Ori');
        $this->source($team, 'trees-of-the-field.odp', 'Trees of the Field');

        $result = app(ServicePlanner::class)->upsert($team, '2026-11-01', 'Music', ['Kumi Ori', 'Trees of the Field']);

        $this->assertSame([], $result['unmatched']);
        GenerateServiceDeck::dispatchSync($result['deck']->id);

        $deck = $result['deck']->fresh();
        $this->assertSame('ready', $deck->status);
        $this->assertSame('26-11-01 Music.odp', DeckController::fileName($deck));
        $this->assertFalse($deck->stale);
        Storage::disk('files')->assertExists($deck->generated_path);

        $local = storage_path('app/merged-check.odp');
        file_put_contents($local, Storage::disk('files')->get($deck->generated_path));
        $zip = new ZipArchive;
        $zip->open($local);
        $xml = $zip->getFromName('content.xml');
        $zip->close();
        @unlink($local);

        $this->assertStringContainsString('Kumi', $xml);
        $this->assertGreaterThan(2, substr_count($xml, '<draw:page'));
    }

    public function test_unknown_titles_are_reported_and_history_counts_tracked_templates(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();
        $this->template($team, 'music-template.odp', 'Music', true);
        $this->source($team, 'kumi-ori.odp', 'Kumi Ori');

        $result = app(ServicePlanner::class)->upsert($team, '2026-11-08', 'Music', ['Kumi Ori', 'Not A Song']);

        $this->assertSame(['songs' => ['Not A Song']], $result['unmatched']);

        $liturgy = $this->template($team, 'music-template.odp', 'Liturgy', false);
        app(ServicePlanner::class)->createDeck($result['service'], $liturgy);

        $this->actingAs($owner)
            ->get(route('service-slides.history', $team))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('items.0.title', 'Kumi Ori')
                ->where('items.0.count', 1)
                ->has('services', 1)
                ->has('services.0.decks', 1)
                ->where('services.0.decks.0.name', 'Music')
                ->missing('services.0.decks.1'));
    }

    public function test_members_without_a_grant_cannot_open_the_library(): void
    {
        [$team, $member] = $this->member();

        $this->actingAs($member)
            ->get(route('service-slides.library', $team))
            ->assertForbidden();
    }

    public function test_a_disabled_module_is_not_found(): void
    {
        [$team, $owner] = $this->owner();
        app(ModuleAccess::class)->setEnabled($team, 'service-slides', false);

        $this->actingAs($owner)
            ->get(route('service-slides.library', $team))
            ->assertNotFound();
    }

    private function template(Team $team, string $fixture, string $name, bool $history): SlideTemplate
    {
        $path = 'templates/'.$fixture;
        Storage::disk('files')->put($path, file_get_contents(base_path('tests/Fixtures/slides/'.$fixture)));
        $local = base_path('tests/Fixtures/slides/'.$fixture);
        $inspector = app(OdpInspector::class);
        $template = SlideTemplate::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'file_path' => $path,
            'page_size' => $inspector->pageSize($local),
            'default_kind' => 'song',
            'is_history_tracked' => $history,
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

    private function upload(string $fixture): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload-');
        copy(base_path('tests/Fixtures/slides/'.$fixture), $path);

        $mimeType = str_ends_with($fixture, '.pptx') ? PptxInspector::MIMETYPE : OdpInspector::MIMETYPE;

        return new UploadedFile($path, $fixture, $mimeType, null, true);
    }

    private function source(Team $team, string $fixture, string $title): SlideSource
    {
        $path = 'slides/'.$fixture;
        Storage::disk('files')->put($path, file_get_contents(base_path('tests/Fixtures/slides/'.$fixture)));

        return SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'song',
            'title' => $title,
            'original_path' => $path,
            'file_path' => $path,
            'slide_count' => 1,
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

    /**
     * @return array{0: Team, 1: User}
     */
    private function member(): array
    {
        [$team] = $this->owner();
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);
        $member->switchTeam($team);

        return [$team, $member];
    }
}
