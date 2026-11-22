<?php

namespace App\Modules\ServiceSlides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Modules\ServiceSlides\Actions\SlideFiles;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Models\SlideTemplateSlide;
use App\Modules\ServiceSlides\Support\Preset;
use App\Modules\ServiceSlides\Support\ResolvesPresentationUpload;
use App\Modules\ServiceSlides\Support\SlideFormats;
use App\Modules\ServiceSlides\Support\SlideLinkDownloader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    use ResolvesPresentationUpload;

    public function index(Team $current_team, Preset $preset): Response
    {
        $templates = SlideTemplate::query()->forTeam($current_team)->with('slides')->withCount('decks')->orderBy('name')->get();
        $templates->each(function (SlideTemplate $template) use ($preset) {
            $template->setAttribute('steps', $preset->steps($template));
            $template->setAttribute('preset_saved', $template->preset !== null);
        });

        return Inertia::render('service-slides/Templates', [
            'templates' => $templates,
            'sources' => SlideSource::query()->forTeam($current_team)->where('archived', false)->orderBy('title')->get(['id', 'title', 'kind', 'original_format']),
            'kinds' => config('modules.slide_kinds'),
        ]);
    }

    public function store(Request $request, Team $current_team, SlideFiles $files, SlideLinkDownloader $links, SlideFormats $formats): RedirectResponse
    {
        $data = $request->validate($this->metadataRules());
        $upload = $this->presentationUpload($request, $links, $formats, kind: $data['default_kind'] ?? null);

        try {
            $template = $files->createTemplate($current_team, $upload, [
                'name' => $data['name'],
                'default_kind' => $data['default_kind'] ?? null,
                'is_history_tracked' => $request->boolean('is_history_tracked'),
            ]);
        } finally {
            $this->forgetTemporaryUpload($upload, $request);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added. Check the suggested preset.', ['name' => $template->name])]);

        return back();
    }

    public function update(Request $request, Team $current_team, SlideTemplate $template, SlideFormats $formats): RedirectResponse
    {
        $this->authorizeTemplate($current_team, $template);
        $data = $request->validate($this->metadataRules());
        $kind = $data['default_kind'] ?? null;

        if ($mismatch = $formats->kindRequiresFormat($kind, $template->format)) {
            throw ValidationException::withMessages(['default_kind' => $mismatch]);
        }

        $template->update([
            'name' => $data['name'],
            'default_kind' => $kind,
            'is_history_tracked' => $request->boolean('is_history_tracked'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    public function updatePreset(Request $request, Team $current_team, SlideTemplate $template, Preset $preset): RedirectResponse
    {
        $this->authorizeTemplate($current_team, $template);
        $template->update(['preset' => $preset->normalize($request->input('steps', []), $template)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preset saved. Existing decks keep their current order.')]);

        return back();
    }

    public function replaceFile(Request $request, Team $current_team, SlideTemplate $template, SlideFiles $files, SlideLinkDownloader $links, SlideFormats $formats): RedirectResponse
    {
        $this->authorizeTemplate($current_team, $template);
        $upload = $this->presentationUpload($request, $links, $formats, $template->format, $template->default_kind);

        try {
            $files->replaceTemplate($template, $upload);
        } finally {
            $this->forgetTemporaryUpload($upload, $request);
        }

        $missing = $template->slides()->where('missing', true)->count();

        Inertia::flash('toast', $missing > 0
            ? ['type' => 'warning', 'message' => trans_choice('File replaced. :count slide is no longer in the file; decks that use it are blocked until fixed.|File replaced. :count slides are no longer in the file; decks that use them are blocked until fixed.', $missing)]
            : ['type' => 'success', 'message' => __('File replaced. Decks that use this template need to be generated again.')]);

        return back();
    }

    public function updateSlide(Request $request, Team $current_team, SlideTemplateSlide $slide): RedirectResponse
    {
        $this->authorizeTemplate($current_team, $slide->template);
        $slide->update($request->validate([
            'label' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['intro', 'separator', 'title'])],
        ]));

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'default_kind' => ['nullable', Rule::in(array_keys(config('modules.slide_kinds')))],
            'is_history_tracked' => ['nullable', 'boolean'],
        ];
    }

    private function authorizeTemplate(Team $team, SlideTemplate $template): void
    {
        abort_unless($template->team_id === $team->id, 404);
    }

    private function forgetTemporaryUpload(UploadedFile $upload, Request $request): void
    {
        if (! $request->hasFile('file')) {
            @unlink($upload->getRealPath());
        }
    }
}
