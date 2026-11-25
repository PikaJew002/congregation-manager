<?php

namespace Tests\Feature\ServiceSlides;

use App\Enums\TeamRole;
use App\Mcp\CongregationServer;
use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Jobs\NameServiceFromCalendar;
use App\Modules\ServiceSlides\Mcp\Tools\UpsertServiceDeck;
use App\Modules\ServiceSlides\Models\Service;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\OdpInspector;
use App\Support\HebrewCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceTitleTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->team = Team::factory()->create();
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
        $this->owner->switchTeam($this->team);
    }

    public function test_a_new_service_without_a_title_is_a_shabbat_morning_service(): void
    {
        $this->calendar([]);

        $this->actingAs($this->owner)
            ->post(route('service-slides.services.store', $this->team->slug), ['service_date' => '2026-11-28'])
            ->assertRedirect();

        $this->assertSame('Shabbat Morning Service', Service::query()->sole()->title);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), HebrewCalendar::URL)
            && $request['start'] === '2026-11-28');
    }

    public function test_a_holiday_service_is_named_for_the_holiday(): void
    {
        $this->calendar([['title' => 'Erev Rosh Hashana', 'category' => 'holiday', 'subcat' => 'major']]);

        $this->actingAs($this->owner)
            ->post(route('service-slides.services.store', $this->team->slug), ['service_date' => '2026-09-11'])
            ->assertRedirect();

        $this->assertSame('Erev Rosh Hashana Service', Service::query()->sole()->title);
    }

    public function test_a_typed_title_is_kept_and_the_calendar_is_not_asked(): void
    {
        $this->calendar([['title' => 'Erev Rosh Hashana', 'category' => 'holiday', 'subcat' => 'major']]);

        $this->actingAs($this->owner)
            ->post(route('service-slides.services.store', $this->team->slug), ['service_date' => '2026-09-11', 'title' => 'Selichot'])
            ->assertRedirect();

        $this->assertSame('Selichot', Service::query()->sole()->title);
        Http::assertNothingSent();
    }

    public function test_opening_an_existing_date_keeps_its_title(): void
    {
        $service = $this->service('2026-11-28', 'Bat Mitzvah');

        $this->actingAs($this->owner)
            ->post(route('service-slides.services.store', $this->team->slug), ['service_date' => '2026-11-28', 'title' => 'Something else'])
            ->assertRedirect();

        $this->assertSame('Bat Mitzvah', $service->fresh()->title);
    }

    public function test_the_lookup_leaves_a_renamed_service_alone_and_survives_an_outage(): void
    {
        $renamed = $this->service('2026-09-11', 'Selichot');
        $default = $this->service('2026-09-12', HebrewCalendar::DEFAULT_SERVICE);
        Http::fake([HebrewCalendar::URL.'*' => Http::response('down', 503)]);

        NameServiceFromCalendar::dispatchSync($renamed->id);
        NameServiceFromCalendar::dispatchSync($default->id);

        $this->assertSame('Selichot', $renamed->fresh()->title);
        $this->assertSame(HebrewCalendar::DEFAULT_SERVICE, $default->fresh()->title);
    }

    public function test_the_mcp_tool_takes_an_optional_service_title(): void
    {
        Storage::fake('files');
        $this->template();
        $this->approveMcp($this->team);

        CongregationServer::actingAs($this->owner)
            ->tool(UpsertServiceDeck::class, ['date' => '2026-11-28', 'template' => 'Music', 'titles' => ['Kumi Ori']])
            ->assertOk()
            ->assertSee('Shabbat Morning Service');

        CongregationServer::actingAs($this->owner)
            ->tool(UpsertServiceDeck::class, ['date' => '2026-11-28', 'template' => 'Music', 'titles' => ['Kumi Ori'], 'service_title' => 'Bat Mitzvah of Sarah'])
            ->assertOk()
            ->assertSee('Bat Mitzvah of Sarah');

        $this->assertSame('Bat Mitzvah of Sarah', Service::query()->sole()->title);
    }

    /**
     * @param  list<array<string, string>>  $items
     */
    private function calendar(array $items): void
    {
        Http::fake([HebrewCalendar::URL.'*' => Http::response(['title' => 'Hebcal', 'items' => $items])]);
    }

    private function service(string $date, string $title): Service
    {
        return Service::query()->create(['team_id' => $this->team->id, 'service_date' => $date, 'title' => $title, 'source' => 'manual']);
    }

    private function template(): void
    {
        $fixture = base_path('tests/Fixtures/slides/music-template.odp');
        Storage::disk('files')->put('templates/music.odp', file_get_contents($fixture));
        $template = SlideTemplate::query()->create([
            'team_id' => $this->team->id,
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
