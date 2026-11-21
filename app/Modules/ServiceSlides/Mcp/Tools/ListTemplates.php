<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Modules\ServiceSlides\Mcp\CongregationContext;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\Preset;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListTemplates extends Tool
{
    protected string $description = 'List slide templates for this congregation, with the slots each deck can fill. A deck only takes library items in its template\'s format (odp or pptx).';

    public function __construct(private CongregationContext $context, private Preset $preset) {}

    public function handle(Request $request): Response
    {
        $team = $this->context->resolve($request);
        if ($team instanceof Response) {
            return $team;
        }

        $templates = SlideTemplate::query()->forTeam($team)->orderBy('name')->get()
            ->map(fn (SlideTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'format' => $template->format,
                'default_kind' => $template->default_kind,
                'is_history_tracked' => $template->is_history_tracked,
                'slots' => collect($this->preset->steps($template))->where('type', 'slot')->map(fn (array $slot) => [
                    'key' => $slot['key'],
                    'label' => $slot['label'],
                    'kind' => $slot['kind'],
                    'repeat' => $slot['repeat'],
                ])->values()->all(),
            ]);

        return Response::json(['templates' => $templates->all()]);
    }
}
