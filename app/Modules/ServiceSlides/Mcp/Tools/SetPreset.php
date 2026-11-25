<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Mcp\CongregationContext;
use App\Mcp\ModuleTool;
use App\Modules\ServiceSlides\Actions\ServicePlanner;
use App\Modules\ServiceSlides\Support\PresetSteps;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SetPreset extends Tool
{
    use ModuleTool;

    protected string $description = 'Replace a template\'s preset with an ordered list of steps. Copy the current steps from list-templates and insert the new slot; slots you omit are removed. A slide step names a template slide by label. A slot step has key, label, kind, and repeat (liturgy slots are false; the music songs slot is true), plus an optional separator slide label and default library titles. An unknown or ambiguous slide label is rejected and the slides are listed. A default title that is not in the library, in this template\'s format, is rejected and the preset is left unchanged. A saved preset applies to decks created afterward. Existing decks keep their entries.';

    public function __construct(
        private CongregationContext $context,
        private ServicePlanner $planner,
        private PresetSteps $steps,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'template' => $schema->string()->description('Template name or default kind, such as Liturgy or liturgy.')->required(),
            'steps' => $schema->array()->items($schema->object([
                'type' => $schema->string()->description('slide or slot.')->required(),
                'label' => $schema->string()->description('Slide label from list-templates, or the slot name.'),
                'key' => $schema->string()->description('Slot key. Lowercase letters, numbers, dashes, and underscores.'),
                'kind' => $schema->string()->description('song, liturgy, or other.'),
                'repeat' => $schema->boolean()->description('True for any number of items. Liturgy slots are false. The music songs slot is true.'),
                'separator' => $schema->string()->description('Slide label inserted after each item in the slot.'),
                'defaults' => $schema->array()->items($schema->string())->description('Library titles that prefill the slot.'),
            ]))->description('The full ordered preset. Slots you omit are removed.')->required(),
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
            'template' => ['required', 'string'],
            ...PresetSteps::rules(required: true),
        ]);

        try {
            $template = $this->planner->template($team, $data['template']);
            $this->steps->store($template, $data['steps']);
        } catch (InvalidArgumentException|ValidationException $exception) {
            return Response::error($this->steps->errorMessage($exception));
        }

        return Response::json($this->steps->present($template->fresh(['slides'])));
    }
}
