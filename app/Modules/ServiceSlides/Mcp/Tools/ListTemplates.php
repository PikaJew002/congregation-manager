<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Modules\ServiceSlides\Mcp\CongregationContext;
use App\Modules\ServiceSlides\Models\SlideTemplate;
use App\Modules\ServiceSlides\Support\PresetSteps;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListTemplates extends Tool
{
    protected string $description = 'List slide templates for this congregation. Each template includes its slides (index, label, role) and its preset steps in order. A slide step names a template slide by label. A slot step includes its key, label, kind, repeat, separator label, and default library titles. When preset_saved is false, steps are the suggestion and have not been saved. A deck only takes library items in its template\'s format (odp or pptx).';

    public function __construct(private CongregationContext $context, private PresetSteps $steps) {}

    public function handle(Request $request): Response
    {
        $team = $this->context->resolve($request);
        if ($team instanceof Response) {
            return $team;
        }

        $templates = SlideTemplate::query()->forTeam($team)->with('slides')->orderBy('name')->get()
            ->map(fn (SlideTemplate $template) => $this->steps->present($template));

        return Response::json(['templates' => $templates->all()]);
    }
}
