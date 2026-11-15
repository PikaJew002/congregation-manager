<?php

namespace App\Modules\ServiceSlides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Modules\ServiceSlides\Actions\ServicePlanner;
use App\Modules\ServiceSlides\Jobs\GenerateServiceDeck;
use App\Modules\ServiceSlides\Models\ServiceDeck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeckController extends Controller
{
    public static function fileName(ServiceDeck $deck): string
    {
        return $deck->service->service_date->toDateString().' '.Str::of($deck->name)->replaceMatches('/[\/\\\\:*?"<>|]+/', '-')->trim().'.odp';
    }

    public function update(Request $request, Team $current_team, ServiceDeck $deck): RedirectResponse
    {
        $this->authorizeDeck($current_team, $deck);
        $deck->update($request->validate(['name' => ['required', 'string', 'max:255']]));

        return back();
    }

    public function destroy(Team $current_team, ServiceDeck $deck): RedirectResponse
    {
        $this->authorizeDeck($current_team, $deck);

        if ($deck->generated_path) {
            Storage::disk('files')->delete($deck->generated_path);
        }
        $deck->delete();

        $deck->service->decks()->get()->each(fn (ServiceDeck $remaining, int $position) => $remaining->update(['position' => $position]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deck removed.', ['name' => $deck->name])]);

        return back();
    }

    public function updateEntries(Request $request, Team $current_team, ServiceDeck $deck, ServicePlanner $planner): RedirectResponse
    {
        $this->authorizeDeck($current_team, $deck);
        $slideIds = $deck->template->slides()->pluck('id')->all();

        $data = $request->validate([
            'entries' => ['present', 'array'],
            'entries.*.entry_type' => ['required', Rule::in(['template_slide', 'source'])],
            'entries.*.slide_template_slide_id' => ['required_if:entries.*.entry_type,template_slide', 'nullable', 'integer', Rule::in($slideIds)],
            'entries.*.slide_source_id' => [
                'required_if:entries.*.entry_type,source',
                'nullable',
                'integer',
                Rule::exists('slide_sources', 'id')->where('team_id', $current_team->id),
            ],
            'entries.*.slot_key' => ['nullable', 'string', 'max:50'],
            'entries.*.auto' => ['nullable', 'boolean'],
        ]);

        $planner->replaceEntries($deck, $data['entries']);

        return back();
    }

    public function reset(Team $current_team, ServiceDeck $deck, ServicePlanner $planner): RedirectResponse
    {
        $this->authorizeDeck($current_team, $deck);
        $planner->resetDeck($deck);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name reset to its preset.', ['name' => $deck->name])]);

        return back();
    }

    public function fill(Request $request, Team $current_team, ServiceDeck $deck, ServicePlanner $planner): RedirectResponse
    {
        $this->authorizeDeck($current_team, $deck);
        $data = $request->validate([
            'slot' => ['nullable', 'string', 'max:50'],
            'titles' => ['required', 'string', 'max:10000'],
        ]);

        $titles = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $data['titles']) ?: [])));

        try {
            $result = filled($data['slot'] ?? null)
                ? $planner->fillSlots($deck, [$data['slot'] => $titles])
                : $planner->fill($deck, $titles);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['slot' => $exception->getMessage()]);
        }

        Inertia::flash('fillResult', ['deck' => $deck->id, ...$result]);

        return back();
    }

    public function generate(Team $current_team, ServiceDeck $deck): RedirectResponse
    {
        $this->authorizeDeck($current_team, $deck);

        if ($deck->blocked) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Fix the entries marked as missing before generating.')]);

            return back();
        }

        $deck->update(['status' => 'generating']);
        GenerateServiceDeck::dispatch($deck->id);

        return back();
    }

    public function download(Team $current_team, ServiceDeck $deck): StreamedResponse
    {
        $this->authorizeDeck($current_team, $deck);
        abort_unless($deck->generated_path && Storage::disk('files')->exists($deck->generated_path), 404);

        return Storage::disk('files')->download($deck->generated_path, self::fileName($deck));
    }

    private function authorizeDeck(Team $team, ServiceDeck $deck): void
    {
        abort_unless($deck->service->team_id === $team->id, 404);
    }
}
