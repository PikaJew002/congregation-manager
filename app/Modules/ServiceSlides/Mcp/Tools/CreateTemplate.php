<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Mcp\CongregationContext;
use App\Mcp\ModuleTool;
use App\Modules\ServiceSlides\Actions\SlideFiles;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\EncodedUpload;
use App\Modules\ServiceSlides\Support\PresetSteps;
use App\Modules\ServiceSlides\Support\SlideFormats;
use App\Modules\ServiceSlides\Support\SlideLinkDownloader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateTemplate extends Tool
{
    use ModuleTool;

    protected string $description = 'Create a slide template from an ODP or PPTX file. Songs must be ODP; liturgy must be PPTX. Send the file as base64, or send a Dropbox or Google Drive share/view/edit link (Drive file links from the Google Drive MCP are accepted). Optional steps replace the suggested preset, which is the structural slides around one repeating slot. A slide step is {"type":"slide","label":"Congregational Prayer"}. A slot step is {"type":"slot","key":"opening","label":"Opening","kind":"liturgy","repeat":false,"defaults":["Psalm 19"]}. Liturgy slots are repeat false. The music songs slot is repeat true. A saved preset applies to decks created afterward.';

    public function __construct(
        private CongregationContext $context,
        private SlideFiles $files,
        private SlideFormats $formats,
        private SlideLinkDownloader $links,
        private EncodedUpload $uploads,
        private PresetSteps $steps,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Template name.')->required(),
            'default_kind' => $schema->string()->description('song (ODP), liturgy (PPTX), or other.')->required(),
            'is_history_tracked' => $schema->boolean()->description('Track library items from this template in history.'),
            'file' => $schema->string()->description('Base64-encoded ODP or PPTX file, optionally as a data URL. Send this or url.'),
            'url' => $schema->string()->description('https Dropbox or Google Drive link. Share, view, edit, and file links are accepted and converted to a direct download.'),
            'steps' => $schema->array()->items($schema->object([
                'type' => $schema->string()->description('slide or slot.')->required(),
                'label' => $schema->string()->description('Slide label from list-templates, or the slot name.'),
                'key' => $schema->string()->description('Slot key. Lowercase letters, numbers, dashes, and underscores.'),
                'kind' => $schema->string()->description('song, liturgy, or other.'),
                'repeat' => $schema->boolean()->description('True for any number of items. Liturgy slots are false. The music songs slot is true.'),
                'separator' => $schema->string()->description('Slide label inserted after each item in the slot.'),
                'defaults' => $schema->array()->items($schema->string())->description('Library titles that prefill the slot.'),
            ]))->description('Ordered preset. Omit to keep the suggested preset. A saved preset applies to decks created afterward.'),
        ];
    }

    protected function module(): string
    {
        return 'service-slides';
    }

    public function handle(Request $request): Response
    {
        $team = $this->context->resolve($request, 'service-slides');
        if ($team instanceof Response) {
            return $team;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'default_kind' => ['required', Rule::in(array_keys(config('modules.slide_kinds')))],
            'is_history_tracked' => ['nullable', 'boolean'],
            'file' => ['required_without:url', 'nullable', 'string'],
            'url' => ['required_without:file', 'nullable', 'string', 'max:2000'],
            ...PresetSteps::rules(required: false),
        ]);

        if (SlideTemplate::query()->forTeam($team)->where('name', $data['name'])->exists()) {
            return Response::error($data['name'].' is already a template for this congregation.');
        }

        $upload = filled($data['file'] ?? null)
            ? $this->uploads->decode($data['file'])
            : $this->links->download($data['url']);

        if (is_string($upload)) {
            return Response::error($upload);
        }

        $template = null;

        try {
            $rejection = $this->formats->rejection($upload->getRealPath(), kind: $data['default_kind']);

            if ($rejection !== null) {
                return Response::error($rejection);
            }

            $template = $this->files->createTemplate($team, $upload, [
                'name' => $data['name'],
                'default_kind' => $data['default_kind'],
                'is_history_tracked' => (bool) ($data['is_history_tracked'] ?? false),
            ]);

            if (isset($data['steps'])) {
                $this->steps->store($template, $data['steps']);
            }
        } catch (InvalidArgumentException|ValidationException $exception) {
            $this->forgetTemplate($template);

            return Response::error($this->steps->errorMessage($exception));
        } finally {
            $this->forgetTemporaryUpload($upload);
        }

        $saved = $template->fresh(['slides']);

        if (! $saved instanceof SlideTemplate) {
            return Response::error('The template could not be stored.');
        }

        return Response::json($this->steps->present($saved));
    }

    private function forgetTemplate(?SlideTemplate $template): void
    {
        if ($template === null) {
            return;
        }

        Storage::disk('files')->delete($template->file_path);
        $template->delete();
    }

    private function forgetTemporaryUpload(UploadedFile $upload): void
    {
        @unlink($upload->getRealPath());
    }
}
