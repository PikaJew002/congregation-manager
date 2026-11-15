<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Modules\ServiceSlides\Mcp\CongregationContext;
use App\Modules\ServiceSlides\Models\SlideSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SearchLibrary extends Tool
{
    protected string $description = 'Search the congregation slide library by title or alias.';

    public function __construct(private CongregationContext $context) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Title or alias to search for.')->required(),
            'kind' => $schema->string()->description('Optional kind: song, liturgy, or other.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = $this->context->resolve($request);
        if ($team instanceof Response) {
            return $team;
        }

        $data = $request->validate([
            'query' => ['required', 'string'],
            'kind' => ['nullable', 'string'],
        ]);

        $sources = SlideSource::query()->forTeam($team)->where('archived', false)->with('aliases')
            ->when($data['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->where(function ($query) use ($data) {
                $query->where('title', 'like', '%'.$data['query'].'%')
                    ->orWhereHas('aliases', fn ($aliases) => $aliases->where('alias', 'like', '%'.$data['query'].'%'));
            })
            ->orderBy('title')
            ->limit(25)
            ->get()
            ->map(fn (SlideSource $source) => [
                'id' => $source->id,
                'title' => $source->title,
                'kind' => $source->kind,
                'aliases' => $source->aliases->pluck('alias')->all(),
            ]);

        return Response::json(['items' => $sources->all()]);
    }
}
