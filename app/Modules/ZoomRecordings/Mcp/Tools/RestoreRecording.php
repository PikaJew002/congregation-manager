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
class RestoreRecording extends Tool
{
    use ModuleTool;

    protected string $name = 'restore-recording';

    protected string $description = 'Bring an archived recording back to the website, in date order. Restoring a recording that is not archived changes nothing.';

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
            'id' => $schema->integer()->description('Recording id from list-recordings with view "archived".')->required(),
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

        $this->updater->handle($recording, ['archived' => false]);

        return Response::structured($this->tools->present($recording->fresh()));
    }
}
