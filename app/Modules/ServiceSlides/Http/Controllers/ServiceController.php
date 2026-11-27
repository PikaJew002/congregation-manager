<?php

namespace App\Modules\ServiceSlides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Modules\ServiceSlides\Actions\ServicePlanner;
use App\Modules\ServiceSlides\Jobs\GenerateServiceDeck;
use App\Modules\ServiceSlides\Models\Service;
use App\Modules\ServiceSlides\Models\ServiceDeck;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\Preset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ServiceController extends Controller
{
    public function index(Team $current_team): Response
    {
        return Inertia::render('service-slides/Services', [
            'services' => Service::query()->forTeam($current_team)
                ->with('decks:id,service_id,name,status,stale,blocked,generated_at')
                ->orderByDesc('service_date')
                ->get(),
            'templates' => SlideTemplate::query()->forTeam($current_team)->orderBy('name')->get(['id', 'name', 'default_kind']),
        ]);
    }

    public function store(Request $request, Team $current_team, ServicePlanner $planner): RedirectResponse
    {
        $data = $request->validate([
            'service_date' => ['required', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'template_ids' => ['nullable', 'array'],
            'template_ids.*' => ['integer', Rule::exists('slide_templates', 'id')->where('team_id', $current_team->id)],
        ]);

        $service = $planner->service($current_team, $data['service_date'], $data['title'] ?? null);

        foreach ($data['template_ids'] ?? [] as $templateId) {
            if (! $service->decks()->where('slide_template_id', $templateId)->exists()) {
                $planner->createDeck($service, SlideTemplate::query()->whereKey($templateId)->firstOrFail());
            }
        }

        if (! $service->wasRecentlyCreated) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('A service already exists on that date, so it was opened instead.')]);
        }

        return to_route('service-slides.services.show', [$current_team, $service]);
    }

    public function show(Team $current_team, Service $service, Preset $preset): Response
    {
        $this->authorizeService($current_team, $service);

        $service->load([
            'decks.entries.templateSlide',
            'decks.entries.source:id,title,kind,slide_count,archived',
            'decks.template.slides',
        ]);

        $service->decks->each(function (ServiceDeck $deck) use ($preset) {
            $deck->template->setAttribute('steps', $preset->steps($deck->template));
        });

        return Inertia::render('service-slides/Service', [
            'service' => $service,
            'sources' => SlideSource::query()->forTeam($current_team)->where('archived', false)
                ->with('aliases:id,slide_source_id,alias')
                ->orderBy('title')
                ->get(['id', 'title', 'kind', 'slide_count', 'original_format']),
            'templates' => SlideTemplate::query()->forTeam($current_team)->orderBy('name')->get(['id', 'name']),
            'kinds' => config('modules.slide_kinds'),
        ]);
    }

    public function update(Request $request, Team $current_team, Service $service): RedirectResponse
    {
        $this->authorizeService($current_team, $service);
        $service->update($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]));

        return back();
    }

    public function destroy(Team $current_team, Service $service): RedirectResponse
    {
        $this->authorizeService($current_team, $service);

        foreach ($service->decks as $deck) {
            if ($deck->generated_path) {
                Storage::disk('files')->delete($deck->generated_path);
            }
        }
        $service->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service deleted.')]);

        return to_route('service-slides.services', $current_team);
    }

    public function storeDeck(Request $request, Team $current_team, Service $service, ServicePlanner $planner): RedirectResponse
    {
        $this->authorizeService($current_team, $service);
        $data = $request->validate([
            'template_id' => [
                'required',
                'integer',
                Rule::exists('slide_templates', 'id')->where('team_id', $current_team->id),
                Rule::unique('service_decks', 'slide_template_id')->where('service_id', $service->id),
            ],
        ], ['template_id.unique' => 'This service already has a deck from that template.']);

        $deck = $planner->createDeck($service, SlideTemplate::query()->whereKey($data['template_id'])->firstOrFail());

        Inertia::flash('deck', $deck->id);

        return back();
    }

    public function generateAll(Team $current_team, Service $service): RedirectResponse
    {
        $this->authorizeService($current_team, $service);
        $queued = 0;
        $blocked = [];

        foreach ($service->decks as $deck) {
            if ($deck->blocked) {
                $blocked[] = $deck->name;

                continue;
            }

            $deck->update(['status' => 'generating']);
            GenerateServiceDeck::dispatch($deck->id);
            $queued++;
        }

        $message = trans_choice('Generating :count deck.|Generating :count decks.', $queued);
        if ($blocked !== []) {
            $message .= ' '.__('Skipped until fixed: :decks.', ['decks' => implode(', ', $blocked)]);
        }

        Inertia::flash('toast', ['type' => $blocked === [] ? 'success' : 'warning', 'message' => $message]);

        return back();
    }

    public function downloadAll(Team $current_team, Service $service): BinaryFileResponse|RedirectResponse
    {
        $this->authorizeService($current_team, $service);
        $ready = $service->decks->filter(fn (ServiceDeck $deck) => $deck->status === 'ready'
            && $deck->generated_path
            && Storage::disk('files')->exists($deck->generated_path));

        if ($ready->isEmpty()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No decks are ready to download. Generate them first.')]);

            return back();
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'slides');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $names = [];

        foreach ($ready as $deck) {
            $name = DeckController::fileName($deck);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            for ($n = 2; in_array($name, $names, true); $n++) {
                $name = "{$base} ({$n}).{$extension}";
            }
            $names[] = $name;
            $zip->addFromString($name, Storage::disk('files')->get($deck->generated_path));
        }
        $zip->close();

        return response()->download($zipPath, $service->service_date->format('y-m-d').' slides.zip')->deleteFileAfterSend();
    }

    public function import(Request $request, Team $current_team, ServicePlanner $planner): RedirectResponse
    {
        $data = $request->validate(['csv' => ['required', 'string', 'max:100000']]);
        $lines = preg_split('/\r\n|\r|\n/', trim($data['csv'])) ?: [];
        $decks = [];
        $unmatched = [];
        $errors = [];

        foreach ($lines as $number => $line) {
            if (trim($line) === '' || ($number === 0 && str_starts_with(strtolower(trim($line)), 'date'))) {
                continue;
            }

            $columns = array_map(fn (?string $column) => trim($column ?? ''), str_getcsv($line));
            if (count($columns) === 3) {
                array_splice($columns, 2, 0, ['']);
            }

            if (count($columns) < 4 || $columns[0] === '' || $columns[1] === '') {
                $errors[] = __('Line :line: expected date,template,slot,titles.', ['line' => $number + 1]);

                continue;
            }

            [$date, $template, $slot, $titles] = $columns;
            $titles = array_values(array_filter(array_map('trim', explode('|', $titles)), fn ($title) => $title !== ''));

            try {
                $result = $slot === ''
                    ? $planner->upsert($current_team, $date, $template, titles: $titles, source: 'import')
                    : $planner->upsert($current_team, $date, $template, slots: [$slot => $titles], source: 'import');
            } catch (InvalidArgumentException $exception) {
                $errors[] = __('Line :line: :message', ['line' => $number + 1, 'message' => $exception->getMessage()]);

                continue;
            }

            $decks[$result['deck']->id] = true;
            foreach ($result['unmatched'] as $key => $missing) {
                $unmatched[] = ['date' => $date, 'template' => $template, 'slot' => $key, 'titles' => $missing];
            }
            array_push($errors, ...$result['notes']);
        }

        Inertia::flash('importResult', [
            'decks' => count($decks),
            'unmatched' => $unmatched,
            'errors' => $errors,
        ]);

        return back();
    }

    private function authorizeService(Team $team, Service $service): void
    {
        abort_unless($service->team_id === $team->id, 404);
    }
}
