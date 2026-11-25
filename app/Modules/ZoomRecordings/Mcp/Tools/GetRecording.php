<?php

namespace App\Modules\ZoomRecordings\Mcp\Tools;

use App\Mcp\ModuleTool;
use App\Modules\ZoomRecordings\Mcp\RecordingTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetRecording extends Tool
{
    use ModuleTool;

    protected string $name = 'get-recording';

    protected string $description = 'Show one recording: its details, where it is in processing, any error, its public stream, MP4 and poster links, and recent activity.';

    public function __construct(private RecordingTools $tools) {}

    protected function module(): string
    {
        return RecordingTools::MODULE;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Recording id from list-recordings.')->required(),
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

        return Response::structured($this->tools->present($recording, withLog: true));
    }
}
