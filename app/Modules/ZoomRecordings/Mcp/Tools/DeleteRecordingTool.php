<?php

namespace App\Modules\ZoomRecordings\Mcp\Tools;

use App\Mcp\ModuleTool;
use App\Modules\ZoomRecordings\Actions\DeleteRecording;
use App\Modules\ZoomRecordings\Mcp\RecordingTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Title('Delete Recording')]
#[IsDestructive]
class DeleteRecordingTool extends Tool
{
    use ModuleTool;

    protected string $name = 'delete-recording';

    protected string $description = 'Permanently delete a recording: its video streams, poster, original Zoom file and record are removed and it leaves the website. This cannot be undone. Prefer archive-recording to take a recording off the website but keep it. confirm_title must be the recording\'s exact current title from get-recording. A recording that is still processing cannot be deleted.';

    public function __construct(
        private RecordingTools $tools,
        private DeleteRecording $delete,
    ) {}

    protected function module(): string
    {
        return RecordingTools::MODULE;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Recording id from list-recordings.')->required(),
            'confirm_title' => $schema->string()->description('The recording\'s exact current title, to confirm the right one is deleted.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $team = $this->tools->team($request);
        if ($team instanceof Response) {
            return $team;
        }

        $data = $request->validate([
            'id' => ['required', 'integer'],
            'confirm_title' => ['required', 'string'],
        ]);
        $recording = $this->tools->find($team, $data['id']);
        if ($recording instanceof Response) {
            return $recording;
        }

        if (trim($data['confirm_title']) !== trim($recording->title)) {
            return Response::error('confirm_title does not match this recording\'s title, "'.$recording->title.'". Nothing was deleted.');
        }

        if (! DeleteRecording::canDelete($recording)) {
            return Response::error('This recording is still processing. Delete it once it has finished or failed. Nothing was deleted.');
        }

        $summary = ['id' => $recording->id, 'title' => $recording->title, 'service_title' => $recording->serviceTitle()];
        $this->delete->handle($recording);

        return Response::structured(['deleted' => true] + $summary);
    }
}
