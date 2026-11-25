<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Mcp\CongregationContext;
use App\Mcp\ModuleTool;
use App\Modules\ServiceSlides\Jobs\GenerateServiceDeck;
use App\Modules\ServiceSlides\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GenerateDeck extends Tool
{
    use ModuleTool;

    protected string $description = 'Queue a merged deck for one service date. It is an ODP or PPTX file, matching the deck\'s template.';

    public function __construct(private CongregationContext $context) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('Service date, YYYY-MM-DD: the day in the congregation\'s time zone (see the server instructions for today).')->required(),
            'template' => $schema->string()->description('Template name or deck name.')->required(),
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
            'date' => ['required', 'date'],
            'template' => ['required', 'string'],
        ]);

        $service = Service::query()->forTeam($team)->whereDate('service_date', $team->localDate($data['date']))->first();
        $deck = $service?->decks()->where(function ($query) use ($data) {
            $query->where('name', $data['template'])
                ->orWhereHas('template', fn ($template) => $template->where('name', $data['template'])->orWhere('default_kind', $data['template']));
        })->first();

        if (! $service || ! $deck || $deck->service_id !== $service->id) {
            return Response::error('No deck matched that date and template.');
        }

        if ($deck->blocked) {
            return Response::error('This deck has entries that point at template slides no longer in the template file. Fix them in the app first.');
        }

        $deck->update(['status' => 'generating']);
        GenerateServiceDeck::dispatch($deck->id);

        return Response::json(['date' => $service->service_date->toDateString(), 'deck' => $deck->name, 'status' => 'queued']);
    }
}
