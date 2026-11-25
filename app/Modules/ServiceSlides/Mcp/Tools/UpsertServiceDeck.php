<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Mcp\CongregationContext;
use App\Mcp\ModuleTool;
use App\Modules\ServiceSlides\Actions\ServicePlanner;
use App\Support\HebrewCalendar;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpsertServiceDeck extends Tool
{
    use ModuleTool;

    protected string $description = 'Create a deck for a date from its template preset, then fill slots with library titles. Send `slots` ({slot_key: [titles]}) to fill named slots, or `titles` when the template has exactly one repeating slot. Only the slots you send are replaced. Returns unmatched titles per slot.';

    public function __construct(private CongregationContext $context, private ServicePlanner $planner) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('Service date, YYYY-MM-DD: the day in the congregation\'s time zone (see the server instructions for today).')->required(),
            'template' => $schema->string()->description('Template name or default kind, such as Music or song.')->required(),
            'titles' => $schema->array()->items($schema->string())->description('Short form: library titles in order, for a template with one repeating slot.'),
            'slots' => $schema->object()->description('Titles per slot key from list_templates, for example {"songs": ["Kumi Ori"], "opening": ["Psalm 19"]}.'),
            'service_title' => $schema->string()->description('Name of the service, without the date. Leave it out for the usual name: a new service is called "'.HebrewCalendar::DEFAULT_SERVICE.'", or the holiday service when the date is a major holiday, such as "Erev Rosh Hashana Service". When given, it also renames an existing service.'),
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
            'titles' => ['required_without:slots', 'array'],
            'titles.*' => ['string'],
            'slots' => ['required_without:titles', 'array'],
            'slots.*' => ['array'],
            'slots.*.*' => ['string'],
            'service_title' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = isset($data['slots'])
                ? $this->planner->upsert($team, $data['date'], $data['template'], slots: $data['slots'], source: 'mcp', serviceTitle: $data['service_title'] ?? null)
                : $this->planner->upsert($team, $data['date'], $data['template'], titles: $data['titles'], source: 'mcp', serviceTitle: $data['service_title'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json([
            'date' => $result['service']->service_date->toDateString(),
            'service_title' => $result['service']->title,
            'deck' => $result['deck']->name,
            'unmatched' => (object) $result['unmatched'],
            'notes' => $result['notes'],
        ]);
    }
}
