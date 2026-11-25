<?php

namespace Tests\Feature\ServiceSlides;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Actions\ServicePlanner;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\OdpInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ServiceSlidesPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_each_page(): void
    {
        config(['inertia.testing.ensure_pages_exist' => false]);
        [$team, $owner] = $this->owner();
        $template = $this->template($team, 'Music', true);
        $this->source($team, 'Kumi Ori');
        $result = app(ServicePlanner::class)->upsert($team, '2026-11-01', 'Music', ['Kumi Ori']);

        $pages = [
            'Library' => [route('service-slides.library', $team->slug), 'sources'],
            'Templates' => [route('service-slides.templates', $team->slug), 'templates'],
            'Services' => [route('service-slides.services', $team->slug), 'services'],
            'Service' => [route('service-slides.services.show', [$team->slug, $result['service']]), 'service'],
            'History' => [route('service-slides.history', $team->slug), 'items'],
            'Settings' => [route('service-slides.settings', $team->slug), 'connections'],
        ];

        foreach ($pages as $component => [$url, $prop]) {
            $this->assertFileExists(resource_path("js/modules/service-slides/pages/{$component}.vue"));

            $this->actingAs($owner)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component("service-slides/{$component}")->has($prop));
        }

        $this->actingAs($owner)
            ->get(route('service-slides.templates', $team->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->where('templates.0.preset_saved', false)
                ->has('templates.0.steps'));

        $this->assertNotNull($template->fresh());
    }

    private function template(Team $team, string $name, bool $history): SlideTemplate
    {
        $fixture = 'music-template.odp';
        $path = 'templates/'.$fixture;
        Storage::fake('files');
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

    private function source(Team $team, string $title): SlideSource
    {
        $fixture = 'kumi-ori.odp';
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
}
