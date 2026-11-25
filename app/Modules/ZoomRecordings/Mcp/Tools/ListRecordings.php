<?php

namespace App\Modules\ZoomRecordings\Mcp\Tools;

use App\Mcp\ModuleTool;
use App\Modules\ZoomRecordings\Mcp\RecordingTools;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListRecordings extends Tool
{
    use ModuleTool;

    protected string $name = 'list-recordings';

    protected string $description = 'List the congregation\'s sermon recordings, newest recording date first. view is "current" (default: published, processing and failed; what the Recordings page shows), "archived" (kept but off the website), "failed", or "all". Each page has at most 25. When the response includes nextCursor, call again with that value as cursor and the same view.';

    private const PAGE = 25;

    public function __construct(private RecordingTools $tools) {}

    protected function module(): string
    {
        return RecordingTools::MODULE;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'view' => $schema->string()->enum(['current', 'archived', 'failed', 'all'])->description('Which recordings to list. Defaults to current.'),
            'cursor' => $schema->string()->description('nextCursor from the previous page. Omit for the first page.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $team = $this->tools->team($request);
        if ($team instanceof Response) {
            return $team;
        }

        $data = $request->validate([
            'view' => ['nullable', 'in:current,archived,failed,all'],
            'cursor' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);
        $view = $data['view'] ?? 'current';
        $offset = (int) ($data['cursor'] ?? 0);

        $query = ZoomRecording::query()->forTeam($team)->with('team')
            ->when($view === 'current', fn ($q) => $q->whereNull('archived_at'))
            ->when($view === 'archived', fn ($q) => $q->whereNotNull('archived_at'))
            ->when($view === 'failed', fn ($q) => $q->whereNull('archived_at')->where('status', 'failed'))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id');

        $page = $query->skip($offset)->take(self::PAGE + 1)->get();
        $result = [
            'view' => $view,
            'recordings' => $page->take(self::PAGE)->map(fn (ZoomRecording $recording) => $this->tools->present($recording))->values()->all(),
        ];

        if ($page->count() > self::PAGE) {
            $result['nextCursor'] = (string) ($offset + self::PAGE);
        }

        if ($view === 'current' && $result['recordings'] === [] && $offset === 0) {
            $result['note'] = 'No current recordings. Archived ones are listed with view "archived".';
        }

        return Response::structured($result);
    }
}
