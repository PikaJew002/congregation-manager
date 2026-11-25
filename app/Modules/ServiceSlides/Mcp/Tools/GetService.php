<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Mcp\CongregationContext;
use App\Mcp\ModuleTool;
use App\Modules\ServiceSlides\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetService extends Tool
{
    use ModuleTool;

    protected string $description = 'Get one service, including each deck and its items.';

    public function __construct(private CongregationContext $context) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('Service date, YYYY-MM-DD: the day in the congregation\'s time zone (see the server instructions for today).')->required(),
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

        $data = $request->validate(['date' => ['required', 'date']]);
        $service = Service::query()->forTeam($team)->whereDate('service_date', $team->localDate($data['date']))->first();

        if (! $service) {
            return Response::error('No service on that date.');
        }

        $service->load('decks.entries.source', 'decks.entries.templateSlide', 'decks.template');

        return Response::json([
            'date' => $service->service_date->toDateString(),
            'title' => $service->title,
            'decks' => $service->decks->map(fn ($deck) => [
                'name' => $deck->name,
                'template' => $deck->template->name,
                'status' => $deck->status,
                'blocked' => $deck->blocked,
                'items' => $deck->entries->map(fn ($entry) => [
                    'title' => $entry->source?->title ?? $entry->templateSlide?->label,
                    'type' => $entry->entry_type,
                    'slot' => $entry->slot_key,
                ])->all(),
            ])->all(),
        ]);
    }
}
