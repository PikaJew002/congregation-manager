<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Mcp\CongregationContext;
use App\Mcp\ModuleTool;
use App\Models\User;
use App\Modules\ServiceSlides\Models\SlideSource;
use App\Modules\ServiceSlides\Support\SlideFileLinks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetLibraryFile extends Tool
{
    use ModuleTool;

    protected string $description = 'Get a download link for a library item\'s ODP or PPTX file. Find the id with search-library. The link expires after 10 minutes; give it to the person to open rather than fetching it yourself.';

    public function __construct(private CongregationContext $context, private SlideFileLinks $links) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Library item id from search-library.')->required(),
            'file' => $schema->string()->enum(SlideFileLinks::FILES)->description('Optional: formatted (default) is the file decks are built from; original is the file as uploaded, before song formatting.'),
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
            'id' => ['required', 'integer'],
            'file' => ['nullable', 'string', Rule::in(SlideFileLinks::FILES)],
        ]);
        $file = ($data['file'] ?? null) ?: 'formatted';

        $source = SlideSource::query()->forTeam($team)->find($data['id']);
        if (! $source instanceof SlideSource) {
            return Response::error('No library item with that id in this congregation.');
        }

        /** @var User $user */
        $user = $request->user();
        $name = $this->links->sourceName($source, $file);

        return Response::json([
            'title' => $source->title,
            'format' => pathinfo($name, PATHINFO_EXTENSION),
            'file' => $file,
            'fileName' => $name,
            ...$this->links->forSource($user, $team, $source, $file),
        ]);
    }
}
