<?php

namespace App\Modules\ZoomRecordings\Mcp\Tools;

use App\Mcp\ModuleTool;
use App\Modules\ZoomRecordings\Actions\UpdateRecording;
use App\Modules\ZoomRecordings\Mcp\RecordingTools;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Title('Update Recording')]
#[IsIdempotent]
class UpdateRecordingDetails extends Tool
{
    use ModuleTool;

    protected string $name = 'update-recording';

    protected string $description = 'Change a recording\'s title, service, speaker or Torah portion. Send only the fields to change; fields you leave out are kept. An empty string clears speaker or torah_portion, and resets service to "'.ZoomRecording::DEFAULT_SERVICE.'". service is only the name, such as "Erev Rosh Hashana Service"; the date is added automatically. The website feed is updated.';

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
            'title' => $schema->string()->description('New title.'),
            'service' => $schema->string()->description('Service name without the date, such as "Shabbat Morning Service".'),
            'speaker' => $schema->string()->description('Who gave the message.'),
            'torah_portion' => $schema->string()->description('Torah portion, such as "Vayetzei".'),
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'service' => ['sometimes', 'nullable', 'string', 'max:255'],
            'speaker' => ['sometimes', 'nullable', 'string', 'max:255'],
            'torah_portion' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $changes = array_intersect_key($data, array_flip(UpdateRecording::FIELDS));
        if ($changes === []) {
            return Response::error('Send at least one of title, service, speaker or torah_portion.');
        }

        $recording = $this->tools->find($team, $data['id']);
        if ($recording instanceof Response) {
            return $recording;
        }

        $this->updater->handle($recording, $changes);

        return Response::structured($this->tools->present($recording->fresh()));
    }
}
