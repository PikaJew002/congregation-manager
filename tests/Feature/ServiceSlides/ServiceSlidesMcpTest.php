<?php

namespace Tests\Feature\ServiceSlides;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Mcp\ServiceSlidesServer;
use App\Modules\ServiceSlides\Mcp\Tools\AddAlias;
use App\Modules\ServiceSlides\Mcp\Tools\AddLibraryItem;
use App\Modules\ServiceSlides\Mcp\Tools\SearchLibrary;
use App\Modules\ServiceSlides\Mcp\Tools\UpsertServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\OdpInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'odp_path' => 'slides/kumi.odp',
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
        $this->assertTrue(Storage::disk('files')->exists($source->odp_path));
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
            ->assertHasErrors(['not an ODP']);
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
            'odp_path' => 'templates/music.odp',
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
