<?php

namespace Tests\Feature\ServiceSlides;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Actions\ServicePlanner;
use App\Modules\ServiceSlides\Jobs\GenerateServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\OdpInspector;
use App\Support\Modules\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_powerpoint_uploads_are_rejected(): void
    {
        [$team, $owner] = $this->owner();

        $this->actingAs($owner)
            ->post(route('service-slides.library.store', $team), [
                'title' => 'Psalm',
                'kind' => 'liturgy',
                'file' => UploadedFile::fake()->create('psalm.pptx', 20, 'application/vnd.openxmlformats-officedocument.presentationml.presentation'),
            ])
            ->assertSessionHasErrors('file');
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
            'odp_path' => $path,
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

    private function source(Team $team, string $fixture, string $title): SlideSource
    {
        $path = 'slides/'.$fixture;
        Storage::disk('files')->put($path, file_get_contents(base_path('tests/Fixtures/slides/'.$fixture)));

        return SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'song',
            'title' => $title,
            'original_path' => $path,
            'odp_path' => $path,
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
