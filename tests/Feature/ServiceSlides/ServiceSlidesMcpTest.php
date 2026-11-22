<?php

namespace Tests\Feature\ServiceSlides;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Actions\ServicePlanner;
use App\Modules\ServiceSlides\Mcp\ServiceSlidesServer;
use App\Modules\ServiceSlides\Mcp\Tools\AddAlias;
use App\Modules\ServiceSlides\Mcp\Tools\AddLibraryItem;
use App\Modules\ServiceSlides\Mcp\Tools\CreateTemplate;
use App\Modules\ServiceSlides\Mcp\Tools\ListTemplates;
use App\Modules\ServiceSlides\Mcp\Tools\SearchLibrary;
use App\Modules\ServiceSlides\Mcp\Tools\SetPreset;
use App\Modules\ServiceSlides\Mcp\Tools\UpsertServiceDeck;
use App\Modules\ServiceSlides\Models\Service;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\OdpInspector;
use App\Modules\ServiceSlides\Support\PptxInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceSlidesMcpTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_mcp_endpoint_requires_a_token(): void
    {
        $this->postJson('/mcp/services', [])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/services').'"');
    }

    public function test_a_congregation_token_can_search_and_plan_a_deck(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();
        $this->template($team);
        SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'song',
            'title' => 'Kumi Ori',
            'original_path' => 'slides/kumi.odp',
            'file_path' => 'slides/kumi.odp',
            'slide_count' => 1,
        ]);

        $token = $owner->createToken('agent', ['service-slides', 'congregation:'.$team->id]);
        $owner->withAccessToken($token->accessToken);

        ServiceSlidesServer::actingAs($owner)
            ->tool(SearchLibrary::class, ['query' => 'Kumi'])
            ->assertOk()
            ->assertSee('Kumi Ori');

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddAlias::class, ['title' => 'Kumi Ori', 'alias' => 'Arise'])
            ->assertOk();

        ServiceSlidesServer::actingAs($owner)
            ->tool(UpsertServiceDeck::class, [
                'date' => '2026-11-01',
                'template' => 'Music',
                'titles' => ['Arise', 'Missing Song'],
            ])
            ->assertOk()
            ->assertSee('Missing Song');

        $this->assertDatabaseHas('services', [
            'team_id' => $team->id,
            'source' => 'mcp',
        ]);
    }

    public function test_a_congregation_token_can_add_a_library_item(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();
        $token = $owner->createToken('agent', ['service-slides', 'congregation:'.$team->id]);
        $owner->withAccessToken($token->accessToken);
        $file = base64_encode((string) file_get_contents(base_path('tests/Fixtures/slides/kumi-ori.odp')));

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'Kumi Ori',
                'kind' => 'song',
                'author' => 'Traditional',
                'aliases' => ['Arise'],
                'file' => $file,
            ])
            ->assertOk()
            ->assertSee('Kumi Ori');

        $source = SlideSource::query()->where('team_id', $team->id)->where('title', 'Kumi Ori')->first();

        $this->assertNotNull($source);
        $this->assertSame('song', $source->kind);
        $this->assertSame('Traditional', $source->author);
        $this->assertGreaterThan(0, $source->slide_count);
        $this->assertTrue(Storage::disk('files')->exists($source->file_path));
        $this->assertDatabaseHas('slide_source_aliases', [
            'slide_source_id' => $source->id,
            'alias' => 'Arise',
        ]);

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'Kumi Ori',
                'kind' => 'song',
                'file' => $file,
            ])
            ->assertHasErrors(['already in this congregation']);

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'Notes',
                'kind' => 'other',
                'file' => base64_encode('not an odp'),
            ])
            ->assertHasErrors(['Upload an ODP or PPTX presentation.']);
    }

    public function test_adon_olam_can_be_both_a_song_and_a_liturgy_item(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();
        $token = $owner->createToken('agent', ['service-slides', 'congregation:'.$team->id]);
        $owner->withAccessToken($token->accessToken);

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'Adon Olam',
                'kind' => 'song',
                'file' => base64_encode((string) file_get_contents(base_path('tests/Fixtures/slides/kumi-ori.odp'))),
            ])
            ->assertOk();

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'Adon Olam',
                'kind' => 'liturgy',
                'file' => base64_encode((string) file_get_contents(base_path('tests/Fixtures/slides/adon-olam.pptx'))),
            ])
            ->assertOk()
            ->assertSee('pptx');

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'Adon Olam',
                'kind' => 'song',
                'file' => base64_encode((string) file_get_contents(base_path('tests/Fixtures/slides/kumi-ori.odp'))),
            ])
            ->assertHasErrors(['already in this congregation\'s library as song']);

        $this->assertSame(2, SlideSource::query()->where('team_id', $team->id)->where('title', 'Adon Olam')->count());

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddAlias::class, ['title' => 'Adon Olam', 'alias' => 'Master of the World'])
            ->assertHasErrors(['Pass its kind']);

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddAlias::class, ['title' => 'Adon Olam', 'kind' => 'liturgy', 'alias' => 'Master of the World'])
            ->assertOk();

        $liturgy = SlideSource::query()->where('team_id', $team->id)->where('title', 'Adon Olam')->where('kind', 'liturgy')->firstOrFail();
        $song = SlideSource::query()->where('team_id', $team->id)->where('title', 'Adon Olam')->where('kind', 'song')->firstOrFail();

        $this->assertTrue($liturgy->aliases()->where('alias', 'Master of the World')->exists());
        $this->assertFalse($song->aliases()->where('alias', 'Master of the World')->exists());
        $this->assertSame($song->id, app(ServicePlanner::class)->match($team, 'Adon Olam', 'odp', 'song')?->id);
        $this->assertSame($liturgy->id, app(ServicePlanner::class)->match($team, 'Adon Olam', 'pptx', 'liturgy')?->id);
    }

    public function test_a_powerpoint_liturgy_item_takes_its_title_but_no_author_from_the_first_slide(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();
        $token = $owner->createToken('agent', ['service-slides', 'congregation:'.$team->id]);
        $owner->withAccessToken($token->accessToken);
        SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'song',
            'title' => 'Adon Olam p 110-111',
            'original_format' => 'odp',
            'original_path' => 'slides/adon-olam.odp',
            'file_path' => 'slides/adon-olam.odp',
            'slide_count' => 1,
        ]);

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'kind' => 'liturgy',
                'file' => base64_encode((string) file_get_contents(base_path('tests/Fixtures/slides/adon-olam.pptx'))),
            ])
            ->assertOk()
            ->assertSee('Adon Olam p 110-111')
            ->assertSee('pptx');

        $source = SlideSource::query()->where('team_id', $team->id)->where('original_format', 'pptx')->firstOrFail();

        $this->assertSame('Adon Olam p 110-111', $source->title);
        $this->assertNull($source->author);
        $this->assertSame(3, $source->slide_count);
        $this->assertSame($source->original_path, $source->file_path);
        $this->assertStringEndsWith('.pptx', $source->file_path);
    }

    public function test_a_dropbox_link_can_add_a_library_item(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();
        $token = $owner->createToken('agent', ['service-slides', 'congregation:'.$team->id]);
        $owner->withAccessToken($token->accessToken);
        $odp = (string) file_get_contents(base_path('tests/Fixtures/slides/kumi-ori.odp'));
        $pptx = (string) file_get_contents(base_path('tests/Fixtures/slides/adon-olam.pptx'));
        $requested = null;

        Http::fake(function ($request) use ($odp, $pptx, &$requested) {
            $requested = $request->url();

            if (str_contains($request->url(), 'preview.odp')) {
                return Http::response('<!DOCTYPE html><html></html>', 200, ['Content-Type' => 'text/html']);
            }

            if (str_contains($request->url(), 'drive.google.com/uc')) {
                return Http::response($pptx, 200, ['Content-Type' => 'application/octet-stream']);
            }

            return Http::response($odp, 200, ['Content-Type' => 'application/octet-stream']);
        });

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'From Dropbox',
                'kind' => 'song',
                'url' => 'https://www.dropbox.com/scl/fi/abc/kumi-ori.odp?rlkey=secret&dl=0',
            ])
            ->assertOk()
            ->assertSee('From Dropbox');

        $this->assertIsString($requested);
        $this->assertStringContainsString('https://www.dropbox.com/scl/fi/abc/kumi-ori.odp', $requested);
        $this->assertStringContainsString('rlkey=secret', $requested);
        $this->assertStringContainsString('dl=1', $requested);
        $this->assertNotNull(SlideSource::query()->where('team_id', $team->id)->where('title', 'From Dropbox')->first());

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'Elsewhere',
                'kind' => 'song',
                'url' => 'https://example.com/song.odp',
            ])
            ->assertHasErrors(['Dropbox or Google Drive']);

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'Preview',
                'kind' => 'song',
                'url' => 'https://www.dropbox.com/scl/fi/abc/preview.odp?dl=0',
            ])
            ->assertHasErrors(['web page']);

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'kind' => 'liturgy',
                'url' => 'https://drive.google.com/file/d/1aOlcIaIVhPZBiJoOiePH1ralnX-Xh1wo/view',
            ])
            ->assertOk()
            ->assertSee('pptx');

        $this->assertTrue(collect(Http::recorded())->contains(fn ($pair) => str_contains($pair[0]->url(), 'drive.google.com/uc?export=download&id=1aOlcIaIVhPZBiJoOiePH1ralnX-Xh1wo')));
    }

    public function test_a_missing_title_and_artist_come_from_the_first_slide(): void
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();
        $token = $owner->createToken('agent', ['service-slides', 'congregation:'.$team->id]);
        $owner->withAccessToken($token->accessToken);

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'kind' => 'song',
                'file' => base64_encode((string) file_get_contents(base_path('tests/Fixtures/slides/kumi-ori.odp'))),
            ])
            ->assertOk()
            ->assertSee('Kumi Ori')
            ->assertSee('Barry and Batya Segal');

        $source = SlideSource::query()->where('team_id', $team->id)->where('title', 'Kumi Ori')->first();

        $this->assertNotNull($source);
        $this->assertSame('Barry and Batya Segal', $source->author);
    }

    public function test_an_owner_can_create_a_token_for_the_current_congregation(): void
    {
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('service-slides.tokens.store', $team), ['name' => 'Claude'])
            ->assertRedirect();

        $this->assertNotNull($owner->tokens()->first());
        $this->assertContains('congregation:'.$team->id, $owner->tokens()->first()->abilities);
    }

    public function test_a_liturgy_template_can_be_created_with_slots_between_slides(): void
    {
        [$team, $owner] = $this->agent();
        [$first, $second] = $this->twoLabels();
        SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'song',
            'title' => 'Adon Olam',
            'original_format' => 'odp',
            'original_path' => 'slides/adon.odp',
            'file_path' => 'slides/adon.odp',
            'slide_count' => 1,
        ]);

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'Adon Olam',
                'kind' => 'liturgy',
                'file' => $this->encoded('adon-olam.pptx'),
            ])
            ->assertOk();

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Liturgy',
                'default_kind' => 'liturgy',
                'is_history_tracked' => true,
                'file' => $this->encoded('liturgy-service.pptx'),
                'steps' => [
                    ['type' => 'slide', 'label' => $first],
                    ['type' => 'slot', 'key' => 'opening', 'label' => 'Opening', 'kind' => 'liturgy', 'repeat' => false, 'defaults' => ['Adon Olam']],
                    ['type' => 'slide', 'label' => $second],
                ],
            ])
            ->assertOk()
            ->assertSee($this->stepThenSlot($first, 'opening'))
            ->assertSee('"defaults":["Adon Olam"]');

        $template = SlideTemplate::query()->where('team_id', $team->id)->where('name', 'Liturgy')->firstOrFail();
        $pptx = SlideSource::query()->where('team_id', $team->id)->where('title', 'Adon Olam')->where('original_format', 'pptx')->firstOrFail();

        $this->assertSame('pptx', $template->format);
        $this->assertTrue($template->is_history_tracked);
        $this->assertSame(['template_slide', 'slot', 'template_slide'], array_column($template->preset, 'type'));
        $this->assertSame('opening', $template->preset[1]['key']);
        $this->assertFalse($template->preset[1]['repeat']);
        $this->assertSame([$pptx->id], $template->preset[1]['default_source_ids']);
    }

    public function test_a_template_without_steps_keeps_one_repeating_slot(): void
    {
        [$team, $owner] = $this->agent();
        [$first] = $this->twoLabels();

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Liturgy',
                'default_kind' => 'liturgy',
                'file' => $this->encoded('liturgy-service.pptx'),
            ])
            ->assertOk()
            ->assertSee($first)
            ->assertSee('"key":"items"')
            ->assertSee('"repeat":true');

        $template = SlideTemplate::query()->where('team_id', $team->id)->where('name', 'Liturgy')->firstOrFail();
        $slots = array_values(array_filter($template->preset, fn (array $step) => $step['type'] === 'slot'));

        $this->assertCount(55, $template->slides);
        $this->assertCount(1, $slots);
        $this->assertSame('items', $slots[0]['key']);
        $this->assertTrue($slots[0]['repeat']);
    }

    public function test_a_duplicate_template_name_or_invalid_file_is_rejected(): void
    {
        [$team, $owner] = $this->agent();

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Liturgy',
                'default_kind' => 'liturgy',
                'file' => $this->encoded('liturgy-service.pptx'),
            ])
            ->assertOk();

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Liturgy',
                'default_kind' => 'liturgy',
                'file' => $this->encoded('liturgy-service.pptx'),
            ])
            ->assertHasErrors(['already a template']);

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Notes',
                'default_kind' => 'liturgy',
                'file' => base64_encode('not a presentation'),
            ])
            ->assertHasErrors(['Upload an ODP or PPTX presentation.']);

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Songs',
                'default_kind' => 'song',
                'file' => $this->encoded('adon-olam.pptx'),
            ])
            ->assertHasErrors(['Songs must be ODP files.']);

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'From Impress',
                'default_kind' => 'liturgy',
                'file' => $this->encoded('kumi-ori.odp'),
            ])
            ->assertHasErrors(['Liturgy must be PPTX files.']);

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Broken',
                'default_kind' => 'liturgy',
                'file' => $this->encoded('liturgy-service.pptx'),
                'steps' => [
                    ['type' => 'slide', 'label' => 'Not A Real Slide'],
                ],
            ])
            ->assertHasErrors(['No slide is labeled "Not A Real Slide"']);

        $this->assertSame(1, SlideTemplate::query()->where('team_id', $team->id)->count());
    }

    public function test_set_preset_replaces_steps_and_list_templates_shows_default_titles(): void
    {
        [$team, $owner] = $this->agent();
        [$first, $second] = $this->twoLabels();

        ServiceSlidesServer::actingAs($owner)
            ->tool(AddLibraryItem::class, [
                'title' => 'Adon Olam',
                'kind' => 'liturgy',
                'file' => $this->encoded('adon-olam.pptx'),
            ])
            ->assertOk();

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Liturgy',
                'default_kind' => 'liturgy',
                'file' => $this->encoded('liturgy-service.pptx'),
            ])
            ->assertOk();

        ServiceSlidesServer::actingAs($owner)
            ->tool(SetPreset::class, [
                'template' => 'Liturgy',
                'steps' => [
                    ['type' => 'slide', 'label' => $first],
                    ['type' => 'slot', 'key' => 'closing', 'label' => 'Closing', 'kind' => 'liturgy', 'repeat' => false, 'defaults' => ['Adon Olam']],
                    ['type' => 'slide', 'label' => $second],
                ],
            ])
            ->assertOk()
            ->assertSee($this->stepThenSlot($first, 'closing'))
            ->assertSee('"defaults":["Adon Olam"]');

        ServiceSlidesServer::actingAs($owner)
            ->tool(ListTemplates::class)
            ->assertOk()
            ->assertSee($this->stepThenSlot($first, 'closing'))
            ->assertSee('"defaults":["Adon Olam"]');

        $template = SlideTemplate::query()->where('team_id', $team->id)->where('name', 'Liturgy')->firstOrFail();

        $this->assertSame(['template_slide', 'slot', 'template_slide'], array_column($template->preset, 'type'));
        $this->assertSame('closing', $template->preset[1]['key']);
    }

    public function test_an_unknown_or_ambiguous_slide_label_is_rejected(): void
    {
        [$team, $owner] = $this->agent();

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Liturgy',
                'default_kind' => 'liturgy',
                'file' => $this->encoded('liturgy-service.pptx'),
            ])
            ->assertOk();

        ServiceSlidesServer::actingAs($owner)
            ->tool(SetPreset::class, [
                'template' => 'Liturgy',
                'steps' => [
                    ['type' => 'slide', 'label' => 'Not A Real Slide'],
                ],
            ])
            ->assertHasErrors(['No slide is labeled "Not A Real Slide"', 'Slides:']);

        $template = SlideTemplate::query()->where('team_id', $team->id)->where('name', 'Liturgy')->firstOrFail();

        foreach ($template->slides()->orderBy('slide_index')->limit(2)->get() as $slide) {
            $slide->update(['label' => 'Shared']);
        }

        ServiceSlidesServer::actingAs($owner)
            ->tool(SetPreset::class, [
                'template' => 'liturgy',
                'steps' => [
                    ['type' => 'slide', 'label' => 'Shared'],
                ],
            ])
            ->assertHasErrors(['More than one slide is labeled "Shared"', 'Slides:']);
    }

    public function test_an_unmatched_default_leaves_the_preset_unchanged(): void
    {
        [$team, $owner] = $this->agent();
        [$first] = $this->twoLabels();

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Liturgy',
                'default_kind' => 'liturgy',
                'file' => $this->encoded('liturgy-service.pptx'),
            ])
            ->assertOk();

        $template = SlideTemplate::query()->where('team_id', $team->id)->where('name', 'Liturgy')->firstOrFail();
        $before = $template->preset;

        ServiceSlidesServer::actingAs($owner)
            ->tool(SetPreset::class, [
                'template' => 'Liturgy',
                'steps' => [
                    ['type' => 'slot', 'key' => 'opening', 'label' => 'Opening', 'kind' => 'liturgy', 'repeat' => false, 'defaults' => ['Missing Prayer']],
                    ['type' => 'slide', 'label' => $first],
                ],
            ])
            ->assertHasErrors(['These default titles are not in the library: Missing Prayer.']);

        $this->assertSame($before, $template->fresh()->preset);
    }

    public function test_set_preset_leaves_an_existing_deck_unchanged(): void
    {
        [$team, $owner] = $this->agent();
        [$first, $second] = $this->twoLabels();

        ServiceSlidesServer::actingAs($owner)
            ->tool(CreateTemplate::class, [
                'name' => 'Liturgy',
                'default_kind' => 'liturgy',
                'file' => $this->encoded('liturgy-service.pptx'),
            ])
            ->assertOk();

        $template = SlideTemplate::query()->where('team_id', $team->id)->where('name', 'Liturgy')->firstOrFail();
        $service = Service::query()->create([
            'team_id' => $team->id,
            'service_date' => '2026-11-22',
            'title' => '2026-11-22',
        ]);
        $deck = app(ServicePlanner::class)->createDeck($service, $template);
        $entries = fn () => $deck->entries()->orderBy('position')->get()->map(fn ($entry) => $entry->only([
            'position', 'entry_type', 'slide_template_slide_id', 'slide_source_id', 'slot_key', 'auto',
        ]))->all();
        $before = $entries();

        $this->assertGreaterThan(1, count($before));

        ServiceSlidesServer::actingAs($owner)
            ->tool(SetPreset::class, [
                'template' => 'Liturgy',
                'steps' => [
                    ['type' => 'slide', 'label' => $first],
                    ['type' => 'slot', 'key' => 'opening', 'label' => 'Opening', 'kind' => 'liturgy', 'repeat' => false],
                    ['type' => 'slide', 'label' => $second],
                ],
            ])
            ->assertOk();

        $this->assertSame($before, $entries());
        $this->assertSame(['template_slide', 'slot', 'template_slide'], array_column($template->fresh()->preset, 'type'));
    }

    /**
     * @return array{0: Team, 1: User}
     */
    private function agent(): array
    {
        Storage::fake('files');
        [$team, $owner] = $this->owner();
        $token = $owner->createToken('agent', ['service-slides', 'congregation:'.$team->id]);
        $owner->withAccessToken($token->accessToken);

        return [$team, $owner];
    }

    private function encoded(string $fixture): string
    {
        return base64_encode((string) file_get_contents(base_path('tests/Fixtures/slides/'.$fixture)));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function twoLabels(): array
    {
        $labels = collect(app(PptxInspector::class)->slides(base_path('tests/Fixtures/slides/liturgy-service.pptx')))
            ->reject(fn (array $slide) => $slide['empty'])
            ->countBy('label')
            ->filter(fn (int $count) => $count === 1)
            ->keys()
            ->values();
        $first = (string) $labels->first();
        $second = (string) $labels->first(fn (string $label) => strcasecmp($label, $first) !== 0);

        $this->assertNotSame('', $first);
        $this->assertNotSame('', $second);

        return [$first, $second];
    }

    private function stepThenSlot(string $label, string $key): string
    {
        $slide = json_encode(['type' => 'slide', 'label' => $label], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $slide.',{"type":"slot","key":"'.$key.'"';
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

    private function template(Team $team): void
    {
        $fixture = base_path('tests/Fixtures/slides/music-template.odp');
        Storage::disk('files')->put('templates/music.odp', file_get_contents($fixture));
        $template = SlideTemplate::query()->create([
            'team_id' => $team->id,
            'name' => 'Music',
            'file_path' => 'templates/music.odp',
            'default_kind' => 'song',
            'is_history_tracked' => true,
        ]);

        foreach (app(OdpInspector::class)->slides($fixture) as $slide) {
            $template->slides()->create([
                'slide_index' => $slide['index'],
                'label' => $slide['label'],
                'role' => $slide['empty'] ? 'separator' : ($slide['index'] === 0 ? 'intro' : 'title'),
            ]);
        }
    }
}
