<?php

namespace App\Modules\ServiceSlides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Modules\ServiceSlides\Actions\SlideFiles;
use App\Modules\ServiceSlides\Models\ServiceDeckEntry;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideSourceAlias;
use App\Modules\ServiceSlides\Support\OdpFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LibraryController extends Controller
{
    public function index(Team $current_team): Response
    {
        $lastUsed = ServiceDeckEntry::query()
            ->join('service_decks', 'service_decks.id', '=', 'service_deck_entries.service_deck_id')
            ->join('services', 'services.id', '=', 'service_decks.service_id')
            ->whereColumn('service_deck_entries.slide_source_id', 'slide_sources.id')
            ->selectRaw('max(services.service_date)');

        return Inertia::render('service-slides/Library', [
            'sources' => SlideSource::query()->forTeam($current_team)
                ->select('slide_sources.*')
                ->selectSub($lastUsed, 'last_used_on')
                ->with('aliases:id,slide_source_id,alias')
                ->orderBy('title')
                ->get(),
            'kinds' => config('modules.slide_kinds'),
        ]);
    }

    public function store(Request $request, Team $current_team, SlideFiles $files): RedirectResponse
    {
        $data = $request->validate([
            ...$this->metadataRules(),
            'file' => ['required', 'file', 'max:102400', new OdpFile],
        ]);

        $source = $files->createSource($current_team, $request->file('file'), collect($data)->except('file')->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':title added to the library.', ['title' => $source->title])]);

        return back();
    }

    public function update(Request $request, Team $current_team, SlideSource $source, SlideFiles $files): RedirectResponse
    {
        $this->authorizeSource($current_team, $source);
        $source->update($request->validate($this->metadataRules()));

        if ($source->wasChanged('kind')) {
            $files->reformatSource($source);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    public function replaceFile(Request $request, Team $current_team, SlideSource $source, SlideFiles $files): RedirectResponse
    {
        $this->authorizeSource($current_team, $source);
        $request->validate(['file' => ['required', 'file', 'max:102400', new OdpFile]]);

        $files->replaceSource($source, $request->file('file'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File replaced. Decks that use it need to be generated again.')]);

        return back();
    }

    public function archive(Team $current_team, SlideSource $source, SlideFiles $files): RedirectResponse
    {
        $this->authorizeSource($current_team, $source);
        $files->setArchived($source, true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':title archived.', ['title' => $source->title])]);

        return back();
    }

    public function restore(Team $current_team, SlideSource $source, SlideFiles $files): RedirectResponse
    {
        $this->authorizeSource($current_team, $source);
        $files->setArchived($source, false);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':title restored.', ['title' => $source->title])]);

        return back();
    }

    public function storeAlias(Request $request, Team $current_team, SlideSource $source): RedirectResponse
    {
        $this->authorizeSource($current_team, $source);
        $data = $request->validate(['alias' => ['required', 'string', 'max:255']]);
        $source->aliases()->firstOrCreate(['alias' => trim($data['alias'])]);

        return back();
    }

    public function destroyAlias(Team $current_team, SlideSourceAlias $alias): RedirectResponse
    {
        $this->authorizeSource($current_team, $alias->source);
        $alias->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(array_keys(config('modules.slide_kinds')))],
            'author' => ['nullable', 'string', 'max:255'],
            'ccli' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function authorizeSource(Team $team, SlideSource $source): void
    {
        abort_unless($source->team_id === $team->id, 404);
    }
}
