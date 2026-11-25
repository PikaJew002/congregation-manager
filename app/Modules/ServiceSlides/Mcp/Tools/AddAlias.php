<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Mcp\CongregationContext;
use App\Mcp\ModuleTool;
use App\Modules\ServiceSlides\Models\SlideSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AddAlias extends Tool
{
    use ModuleTool;

    protected string $description = 'Add an alternate title for a library item. When more than one item shares the title, pass kind.';

    public function __construct(private CongregationContext $context) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Existing library title.')->required(),
            'alias' => $schema->string()->description('Alternate title to store.')->required(),
            'kind' => $schema->string()->description('song, liturgy, or other. Required when more than one item has this title.'),
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
            'title' => ['required', 'string'],
            'alias' => ['required', 'string', 'max:255'],
            'kind' => ['nullable', Rule::in(array_keys(config('modules.slide_kinds')))],
        ]);

        $sources = SlideSource::query()->forTeam($team)->where('title', $data['title'])->where('archived', false)
            ->when($data['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->get();

        if ($sources->count() > 1) {
            return Response::error('More than one library item is named "'.$data['title'].'". Pass its kind.');
        }

        $source = $sources->first();

        if (! $source) {
            return Response::error('No library item has that title.');
        }

        $source->aliases()->firstOrCreate(['alias' => $data['alias']]);

        return Response::json(['title' => $source->title, 'alias' => $data['alias']]);
    }
}
