<?php

namespace Tests\Feature\ServiceSlides;

use App\Enums\TeamRole;
use App\Mcp\CongregationServer;
use App\Models\Team;
use App\Models\User;
use App\Modules\ServiceSlides\Actions\SlideFiles;
use App\Modules\ServiceSlides\Mcp\Tools\SearchLibrary;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Support\LibrarySearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Tests\TestCase;
use ZipArchive;

class LibraryContentSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    public function test_an_upload_stores_every_line_and_keeps_it_out_of_the_page(): void
    {
        config(['inertia.testing.ensure_pages_exist' => false]);
        [$team, $owner] = $this->owner();
        $source = $this->upload($team, 'Blessed Be Your Name', 'blessed-be-your-name.odp');

        $this->assertSame(['Every blessing You pour out I\'ll', 'Turn back to praise', 'When the darkness closes in, Lord', 'Still I will say'], $source->slide_text[3]);
        $this->assertCount($source->slide_count, $source->slide_text);
        $this->assertStringContainsString('every blessing you pour out ill turn back to praise when the darkness', (string) $source->search_text);

        $this->actingAs($owner)
            ->get(route('service-slides.library', $team->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sources.0.title', 'Blessed Be Your Name')
                ->missing('sources.0.slide_text')
                ->missing('sources.0.search_text'));
    }

    public function test_powerpoint_text_is_searchable_and_speaker_notes_are_not(): void
    {
        [$team] = $this->owner();
        $this->upload($team, 'Modim', 'modim.pptx', 'liturgy');
        $notes = $this->upload($team, 'Kumi Ori', $this->withSpeakerNotes('kumi-ori.odp', 'Remember the tambourines'));

        $found = $this->search($team, 'eternally grateful', scope: 'content');
        $this->assertSame(['Modim'], array_column($found, 'title'));
        $this->assertSame(1, $found[0]['slide']);

        $this->assertStringNotContainsString('tambourines', (string) $notes->search_text);
        $this->assertSame([], $this->search($team, 'tambourines'));
    }

    public function test_titles_rank_before_aliases_before_slide_text_and_phrases_match_across_lines(): void
    {
        [$team] = $this->owner();
        $this->item($team, 'Turn Back to Praise (Reprise)', [['Hallelujah']]);
        $this->item($team, 'Reprise', [['Amen']], aliases: ['Turn back to praise']);
        $this->item($team, 'Blessed Be Your Name', [
            ['Blessed Be Your Name', 'Matt Redman'],
            ['Every blessing You pour out I’ll', 'Turn back to praise'],
        ]);

        $found = $this->search($team, 'turn back to praise');
        $this->assertSame(['Turn Back to Praise (Reprise)', 'Reprise', 'Blessed Be Your Name'], array_column($found, 'title'));
        $this->assertSame(['title', 'alias', 'content'], array_column($found, 'match'));
        $this->assertArrayNotHasKey('excerpt', $found[0]);
        $this->assertSame(2, $found[2]['slide']);
        $this->assertSame('Turn back to praise', $found[2]['excerpt']);

        // A straight apostrophe finds a curly one, and the phrase wraps onto the next line.
        $wrapped = $this->search($team, "pour out I'll turn back");
        $this->assertSame(['Blessed Be Your Name'], array_column($wrapped, 'title'));
        $this->assertSame('Every blessing You pour out I’ll / Turn back to praise', $wrapped[0]['excerpt']);

        // Every word must appear, in any order.
        $this->assertSame(['Blessed Be Your Name'], array_column($this->search($team, 'praise BLESSING'), 'title'));
        $this->assertSame([], $this->search($team, 'praise thunder'));
    }

    public function test_hebrew_matches_without_vowel_points(): void
    {
        [$team] = $this->owner();
        $this->item($team, 'Kumi Ori', [['Kumi Ori'], ['קוּמִי אוֹרִי כִּי בָא אוֹרֵךְ', 'Kumi ori, ki va orech']]);

        $found = $this->search($team, 'כי בא אורך');

        $this->assertSame(['Kumi Ori'], array_column($found, 'title'));
        $this->assertSame(2, $found[0]['slide']);
    }

    public function test_scope_kind_archived_and_team_limit_the_results(): void
    {
        [$team] = $this->owner();
        [$other] = $this->owner();
        $this->item($team, 'Grace Alone', [['Hallelujah']]);
        $this->item($team, 'Amazing', [['Amazing grace how sweet the sound']]);
        $this->item($team, 'Aleinu', [['Grace and peace to you']], kind: 'liturgy');
        $this->item($team, 'Old Grace Song', [['Grace']], archived: true);
        $this->item($other, 'Elsewhere', [['Grace upon grace']]);

        $this->assertSame(['Grace Alone', 'Aleinu', 'Amazing'], array_column($this->search($team, 'grace'), 'title'));
        $this->assertSame(['Grace Alone'], array_column($this->search($team, 'grace', scope: 'titles'), 'title'));
        $this->assertSame(['Aleinu', 'Amazing'], array_column($this->search($team, 'grace', scope: 'content'), 'title'));
        $this->assertSame(['Grace Alone', 'Amazing'], array_column($this->search($team, 'grace', kind: 'song'), 'title'));

        $withArchived = app(LibrarySearch::class)->search($team, 'grace', withArchived: true)['items'];
        $this->assertContains('Old Grace Song', array_column($withArchived, 'title'));
        $this->assertNotContains('Elsewhere', array_column($withArchived, 'title'));
    }

    public function test_like_wildcards_in_a_query_are_literal(): void
    {
        [$team] = $this->owner();
        $this->item($team, '100% Yours', [['All of me']]);
        $this->item($team, '1000 Tongues', [['O for a thousand tongues']]);
        $this->item($team, 'Hymn_2', [['Second hymn']]);

        $this->assertSame(['100% Yours'], array_column($this->search($team, '0%'), 'title'));
        $this->assertSame(['Hymn_2'], array_column($this->search($team, 'n_2'), 'title'));
        $this->assertSame(['100% Yours'], array_column($this->search($team, '%'), 'title'));
    }

    public function test_search_library_tool_returns_slide_matches_and_pages_across_ranks(): void
    {
        [$team, $owner] = $this->owner();
        $this->approveMcp($team);
        $this->item($team, 'Grace Alone', [['Hallelujah']]);
        foreach (range(1, 25) as $number) {
            $this->item($team, sprintf('Song %02d', $number), [['Title slide'], ['Your grace is enough']]);
        }

        $first = $this->tool($owner, ['query' => 'grace']);
        $this->assertCount(25, $first['items']);
        $this->assertSame(['title', 'Grace Alone'], [$first['items'][0]['match'], $first['items'][0]['title']]);
        $this->assertSame(['content', 2, 'Your grace is enough'], [$first['items'][1]['match'], $first['items'][1]['slide'], $first['items'][1]['excerpt']]);
        $this->assertSame('Song 24', $first['items'][24]['title']);

        $second = $this->tool($owner, ['query' => 'grace', 'cursor' => $first['nextCursor']]);
        $this->assertSame(['Song 25'], array_column($second['items'], 'title'));
        $this->assertArrayNotHasKey('nextCursor', $second);

        $titles = $this->tool($owner, ['query' => 'grace', 'scope' => 'titles']);
        $this->assertSame(['Grace Alone'], array_column($titles['items'], 'title'));

        CongregationServer::actingAs($owner)
            ->tool(SearchLibrary::class, ['query' => 'grace', 'scope' => 'content', 'cursor' => $first['nextCursor']])
            ->assertHasErrors(['That cursor is not valid for this search.']);
        CongregationServer::actingAs($owner)
            ->tool(SearchLibrary::class, ['query' => 'grace', 'scope' => 'lyrics'])
            ->assertHasErrors();
    }

    public function test_the_library_search_endpoint_returns_slide_matches_for_the_current_team(): void
    {
        [$team, $owner] = $this->owner();
        [$other] = $this->owner();
        $amazing = $this->item($team, 'Amazing', [['Amazing'], ['Amazing grace how sweet the sound']]);
        $this->item($team, 'Grace Alone', [['Hallelujah']]);
        $retired = $this->item($team, 'Old Song', [['Grace']], archived: true);
        $this->item($other, 'Elsewhere', [['Grace upon grace']]);

        $this->actingAs($owner)
            ->getJson(route('service-slides.library.search', [$team->slug, 'q' => 'grace']))
            ->assertOk()
            ->assertExactJson(['items' => [['id' => $amazing->id, 'slide' => 2, 'excerpt' => 'Amazing grace how sweet the sound']]]);

        $this->actingAs($owner)
            ->getJson(route('service-slides.library.search', [$team->slug, 'q' => 'grace', 'archived' => 1]))
            ->assertOk()
            ->assertJsonPath('items.1.id', $retired->id);

        $this->actingAs($owner)
            ->getJson(route('service-slides.library.search', [$team->slug, 'q' => 'grace', 'kind' => 'liturgy']))
            ->assertExactJson(['items' => []]);

        $this->actingAs($owner)
            ->getJson(route('service-slides.library.search', [$team->slug]))
            ->assertUnprocessable();
    }

    public function test_the_backfill_reads_items_without_slide_text_and_reports_unreadable_files(): void
    {
        [$team] = $this->owner();
        Storage::disk('files')->put('slides/kumi.odp', (string) file_get_contents(base_path('tests/Fixtures/slides/kumi-ori.odp')));
        $kumi = $this->bare($team, 'Kumi Ori', 'slides/kumi.odp');
        $missing = $this->bare($team, 'Missing', 'slides/missing.odp');
        $done = $this->item($team, 'Already Read', [['Untouched']]);

        $this->artisan('service-slides:index-slide-text')
            ->expectsOutputToContain('Missing')
            ->assertFailed();

        $this->assertStringContainsString('arise shine for your light has come', (string) $kumi->fresh()->search_text);
        $this->assertNull($missing->fresh()->search_text);
        $this->assertSame([['Untouched']], $done->fresh()->slide_text);
        $this->assertSame(['Kumi Ori'], array_column($this->search($team, 'thick darkness'), 'title'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(Team $team, string $query, ?string $kind = null, string $scope = 'all'): array
    {
        return app(LibrarySearch::class)->search($team, $query, $kind, $scope)['items'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(User $owner, array $arguments): array
    {
        $response = CongregationServer::actingAs($owner)->tool(SearchLibrary::class, $arguments)->assertOk();
        $content = (new ReflectionMethod($response, 'content'))->invoke($response);

        return json_decode($content[0], true);
    }

    /**
     * A library item made straight from slide lines, without a file.
     *
     * @param  list<list<string>>  $slides
     * @param  list<string>  $aliases
     */
    private function item(Team $team, string $title, array $slides, string $kind = 'song', bool $archived = false, array $aliases = []): SlideSource
    {
        $source = SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => $kind,
            'title' => $title,
            'original_path' => 'slides/item.odp',
            'file_path' => 'slides/item.odp',
            'slide_count' => count($slides),
            'archived' => $archived,
            ...app(SlideFiles::class)->slideTextAttributes(array_map(fn (array $lines) => ['lines' => $lines], $slides)),
        ]);

        foreach ($aliases as $alias) {
            $source->aliases()->create(['alias' => $alias]);
        }

        return $source;
    }

    /**
     * An item added before content search, with no slide text yet.
     */
    private function bare(Team $team, string $title, string $path): SlideSource
    {
        return SlideSource::query()->create([
            'team_id' => $team->id,
            'kind' => 'song',
            'title' => $title,
            'original_path' => $path,
            'file_path' => $path,
            'slide_count' => 1,
        ]);
    }

    private function upload(Team $team, string $title, string $fixture, string $kind = 'song'): SlideSource
    {
        $path = str_starts_with($fixture, '/') ? $fixture : base_path('tests/Fixtures/slides/'.$fixture);
        $file = new UploadedFile($path, basename($path), null, null, true);

        return app(SlideFiles::class)->createSource($team, $file, ['title' => $title, 'kind' => $kind]);
    }

    private function withSpeakerNotes(string $fixture, string $note): string
    {
        $path = storage_path('framework/testing/notes-'.$fixture);
        copy(base_path('tests/Fixtures/slides/'.$fixture), $path);
        $zip = new ZipArchive;
        $zip->open($path);
        $content = (string) $zip->getFromName('content.xml');
        $notes = '<presentation:notes><draw:frame svg:x="1cm" svg:y="1cm" svg:width="10cm" svg:height="5cm" presentation:class="notes"><draw:text-box><text:p>'.$note.'</text:p></draw:text-box></draw:frame></presentation:notes>';
        $zip->addFromString('content.xml', preg_replace('#</draw:page>#', $notes.'</draw:page>', $content, 1));
        $zip->close();

        return $path;
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
