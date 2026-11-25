<?php

namespace App\Modules\ZoomRecordings\Mcp\Tools;

use App\Mcp\ModuleTool;
use App\Modules\ZoomRecordings\Actions\UpdateRecording;
use App\Modules\ZoomRecordings\Mcp\RecordingTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class ArchiveRecording extends Tool
{
    use ModuleTool;

    protected string $name = 'archive-recording';

    protected string $description = 'Take a recording off the website but keep all its files. It moves to the archived view and can be brought back with restore-recording. Archiving an archived recording changes nothing.';

    public function __construct(
        private RecordingTools $tools,
        private UpdateRecording $updater,
    ) {}

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

        $this->updater->handle($recording, ['archived' => true]);

        return Response::structured($this->tools->present($recording->fresh()));
    }
}
