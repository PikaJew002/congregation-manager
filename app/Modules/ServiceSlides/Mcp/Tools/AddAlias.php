<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Modules\ServiceSlides\Mcp\CongregationContext;
use App\Modules\ServiceSlides\Models\SlideSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AddAlias extends Tool
{
    protected string $description = 'Add an alternate title for a library item.';

    public function __construct(private CongregationContext $context) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Existing library title.')->required(),
            'alias' => $schema->string()->description('Alternate title to store.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = $this->context->resolve($request);
        if ($team instanceof Response) {
            return $team;
        }

        $data = $request->validate([
            'title' => ['required', 'string'],
            'alias' => ['required', 'string', 'max:255'],
        ]);

        $source = SlideSource::query()->forTeam($team)->where('title', $data['title'])->where('archived', false)->first();

        if (! $source) {
            return Response::error('No library item has that title.');
        }

        $source->aliases()->firstOrCreate(['alias' => $data['alias']]);

        return Response::json(['title' => $source->title, 'alias' => $data['alias']]);
    }
}
