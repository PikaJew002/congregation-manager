<?php

namespace App\Modules\ServiceSlides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Modules\ServiceSlides\Actions\SlideFiles;
use App\Modules\ServiceSlides\Models\ServiceDeckEntry;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideSourceAlias;
use App\Modules\ServiceSlides\Support\LibrarySearch;
use App\Modules\ServiceSlides\Support\ResolvesPresentationUpload;
use App\Modules\ServiceSlides\Support\SlideFileLinks;
use App\Modules\ServiceSlides\Support\SlideFormats;
use App\Modules\ServiceSlides\Support\SlideLinkDownloader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibraryController extends Controller
{
    use ResolvesPresentationUpload;

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
                ->selectRaw('slide_sources.original_path <> slide_sources.file_path as has_original_file')
                ->with('aliases:id,slide_source_id,alias')
                ->orderBy('title')
                ->get(),
            'kinds' => config('modules.slide_kinds'),
        ]);
    }

    /**
     * The file decks are built from, or with file=original the upload as received.
     */
    public function download(Request $request, Team $current_team, SlideSource $source, SlideFileLinks $links): StreamedResponse
    {
        $this->authorizeSource($current_team, $source);
        $file = $request->validate(['file' => ['nullable', Rule::in(SlideFileLinks::FILES)]])['file'] ?? 'formatted';
        $path = $links->sourcePath($source, $file);
        abort_unless(Storage::disk('files')->exists($path), 404);

        return Storage::disk('files')->download($path, $links->sourceName($source, $file));
    }

    /**
     * Library items whose slide text matches, for pages that filter titles in the browser.
     */
    public function search(Request $request, Team $current_team, LibrarySearch $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'kind' => ['nullable', 'string'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $found = $search->search(
            $current_team,
            $data['q'],
            ($data['kind'] ?? null) ?: null,
            'content',
            withArchived: (bool) ($data['archived'] ?? false),
            limit: 50,
        );

        return response()->json([
            'items' => array_map(fn (array $item) => [
                'id' => $item['id'],
                'slide' => $item['slide'] ?? null,
                'excerpt' => $item['excerpt'] ?? null,
            ], $found['items']),
        ]);
    }

    public function store(Request $request, Team $current_team, SlideFiles $files, SlideLinkDownloader $links, SlideFormats $formats): RedirectResponse
    {
        $data = $request->validate($this->metadataRules());
        $this->rejectDuplicate($current_team, $data['title'], $data['kind']);
        $upload = $this->presentationUpload($request, $links, $formats, kind: $data['kind']);

        try {
            $source = $files->createSource($current_team, $upload, $data);
        } finally {
            $this->forgetTemporaryUpload($upload, $request);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':title added to the library.', ['title' => $source->title])]);

        return back();
    }

    public function update(Request $request, Team $current_team, SlideSource $source, SlideFiles $files, SlideFormats $formats): RedirectResponse
    {
        $this->authorizeSource($current_team, $source);
        $data = $request->validate($this->metadataRules());
        $this->rejectDuplicate($current_team, $data['title'], $data['kind'], $source->id);

        if ($mismatch = $formats->kindRequiresFormat($data['kind'], $source->original_format)) {
            throw ValidationException::withMessages(['kind' => $mismatch]);
        }

        $source->update($data);

        if ($source->wasChanged('kind')) {
            $files->reformatSource($source);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    public function replaceFile(Request $request, Team $current_team, SlideSource $source, SlideFiles $files, SlideLinkDownloader $links, SlideFormats $formats): RedirectResponse
    {
        $this->authorizeSource($current_team, $source);
        $upload = $this->presentationUpload($request, $links, $formats, $source->original_format, $source->kind);

        try {
            $files->replaceSource($source, $upload);
        } finally {
            $this->forgetTemporaryUpload($upload, $request);
        }

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

        if (SlideSource::activeDuplicate($current_team, $source->title, $source->kind, $source->id)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => SlideSource::duplicateMessage($source->title, $source->kind)]);

            return back();
        }

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

    private function rejectDuplicate(Team $team, string $title, string $kind, ?int $exceptId = null): void
    {
        if (SlideSource::activeDuplicate($team, $title, $kind, $exceptId)) {
            throw ValidationException::withMessages([
                'title' => SlideSource::duplicateMessage($title, $kind),
            ]);
        }
    }

    private function authorizeSource(Team $team, SlideSource $source): void
    {
        abort_unless($source->team_id === $team->id, 404);
    }

    private function forgetTemporaryUpload(UploadedFile $upload, Request $request): void
    {
        if (! $request->hasFile('file')) {
            @unlink($upload->getRealPath());
        }
    }
}
