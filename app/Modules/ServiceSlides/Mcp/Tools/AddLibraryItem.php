<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Modules\ServiceSlides\Actions\SlideFiles;
use App\Modules\ServiceSlides\Mcp\CongregationContext;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Support\OdpInspector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AddLibraryItem extends Tool
{
    protected string $description = 'Add an ODP file to this congregation\'s slide library. Send the file as base64. PowerPoint files are rejected.';

    public function __construct(private CongregationContext $context, private SlideFiles $files, private OdpInspector $inspector) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Library title used when planning a service.')->required(),
            'kind' => $schema->string()->description('song, liturgy, or other.')->required(),
            'file' => $schema->string()->description('Base64-encoded ODP file, optionally as a data URL.')->required(),
            'author' => $schema->string()->description('Optional author.'),
            'ccli' => $schema->string()->description('Optional CCLI number.'),
            'notes' => $schema->string()->description('Optional notes.'),
            'aliases' => $schema->array()->items($schema->string())->description('Optional alternate titles.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = $this->context->resolve($request);
        if ($team instanceof Response) {
            return $team;
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(array_keys(config('modules.slide_kinds')))],
            'file' => ['required', 'string'],
            'author' => ['nullable', 'string', 'max:255'],
            'ccli' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['string', 'max:255'],
        ]);

        $title = trim($data['title']);
        $existing = SlideSource::query()->forTeam($team)->where('title', $title)->where('archived', false)->first();

        if ($existing) {
            return Response::error($title.' is already in this congregation\'s library.');
        }

        $upload = $this->odpUpload($data['file']);

        if (is_string($upload)) {
            return Response::error($upload);
        }

        try {
            $rejection = $this->inspector->rejection($upload->getRealPath());

            if ($rejection !== null) {
                return Response::error($rejection);
            }

            $source = $this->files->createSource($team, $upload, [
                'title' => $title,
                'kind' => $data['kind'],
                'author' => $data['author'] ?? null,
                'ccli' => $data['ccli'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['aliases'] ?? [] as $alias) {
                $alias = trim($alias);

                if ($alias !== '') {
                    $source->aliases()->firstOrCreate(['alias' => $alias]);
                }
            }
        } finally {
            @unlink($upload->getRealPath());
        }

        $source->load('aliases');

        return Response::json([
            'id' => $source->id,
            'title' => $source->title,
            'kind' => $source->kind,
            'slide_count' => $source->slide_count,
            'aliases' => $source->aliases->pluck('alias')->all(),
        ]);
    }

    private function odpUpload(string $encoded): UploadedFile|string
    {
        $encoded = preg_replace('/\s+/', '', $encoded) ?? '';

        if (str_starts_with($encoded, 'data:') && str_contains($encoded, ',')) {
            $encoded = substr($encoded, (int) strpos($encoded, ',') + 1);
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            return 'The file must be a base64-encoded ODP.';
        }

        if (strlen($bytes) > 102400 * 1024) {
            return 'The ODP file is larger than 100 MB.';
        }

        $temporary = tempnam(sys_get_temp_dir(), 'library-odp-');

        if ($temporary === false) {
            return 'The file could not be stored.';
        }

        file_put_contents($temporary, $bytes);

        return new UploadedFile($temporary, 'library.odp', OdpInspector::MIMETYPE, null, true);
    }
}
