<?php

namespace App\Modules\ZoomRecordings\Mcp\Tools;

use App\Mcp\ModuleTool;
use App\Modules\ZoomRecordings\Actions\DispatchRecordingPipeline;
use App\Modules\ZoomRecordings\Mcp\RecordingTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class RetryRecording extends Tool
{
    use ModuleTool;

    protected string $name = 'retry-recording';

    protected string $description = 'Retry a failed recording from the step that failed (its failed_step and error are in get-recording). Only failed recordings can be retried. Processing runs in the background; check progress with get-recording.';

    public function __construct(
        private RecordingTools $tools,
        private DispatchRecordingPipeline $pipeline,
    ) {}

    protected function module(): string
    {
        return RecordingTools::MODULE;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Recording id from list-recordings with view "failed".')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $team = $this->tools->team($request);
        if ($team instanceof Response) {
            return $team;
        }

        $data = $request->validate(['id' => ['required', 'integer']]);
        $recording = $this->tools->find($team, $data['id']);
        if ($recording instanceof Response) {
            return $recording;
        }

        if ($recording->status !== 'failed') {
            return Response::error('Only failed recordings can be retried. This one is '.$this->tools->state($recording).'.');
        }

        $this->pipeline->handle($recording, $recording->failed_step);

        return Response::structured($this->tools->present($recording->fresh()));
    }
}
