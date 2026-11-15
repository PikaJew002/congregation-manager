<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Modules\ServiceSlides\Mcp\CongregationContext;
use App\Modules\ServiceSlides\Models\Service;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListServices extends Tool
{
    protected string $description = 'List recent services and their decks.';

    public function __construct(private CongregationContext $context) {}

    public function handle(Request $request): Response
    {
        $team = $this->context->resolve($request);
        if ($team instanceof Response) {
            return $team;
        }

        $services = Service::query()->forTeam($team)->with('decks')->orderByDesc('service_date')->limit(30)->get()
            ->map(fn (Service $service) => [
                'date' => $service->service_date->toDateString(),
                'title' => $service->title,
                'decks' => $service->decks->pluck('name')->all(),
            ]);

        return Response::json(['services' => $services->all()]);
    }
}
