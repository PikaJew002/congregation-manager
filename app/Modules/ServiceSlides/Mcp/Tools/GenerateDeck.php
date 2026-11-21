<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Modules\ServiceSlides\Jobs\GenerateServiceDeck;
use App\Modules\ServiceSlides\Mcp\CongregationContext;
use App\Modules\ServiceSlides\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GenerateDeck extends Tool
{
    protected string $description = 'Queue a merged deck for one service date. It is an ODP or PPTX file, matching the deck\'s template.';

    public function __construct(private CongregationContext $context) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('Service date, YYYY-MM-DD.')->required(),
            'template' => $schema->string()->description('Template name or deck name.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = $this->context->resolve($request);
        if ($team instanceof Response) {
            return $team;
        }

        $data = $request->validate([
            'date' => ['required', 'date'],
            'template' => ['required', 'string'],
        ]);

        $service = Service::query()->forTeam($team)->whereDate('service_date', $data['date'])->first();
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

        return Response::json(['date' => $data['date'], 'deck' => $deck->name, 'status' => 'queued']);
    }
}
