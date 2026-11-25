<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Mcp\CongregationContext;
use App\Mcp\ModuleTool;
use App\Modules\ServiceSlides\Models\SlideSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SearchLibrary extends Tool
{
    use ModuleTool;

    private const PAGE_SIZE = 25;

    protected string $description = 'Search the congregation slide library by title or alias. Each page has at most 25 matches. When the response includes nextCursor, call again with that value as cursor and the same query and kind.';

    public function __construct(private CongregationContext $context) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Title or alias to search for.')->required(),
            'kind' => $schema->string()->description('Optional kind: song, liturgy, or other.'),
            'cursor' => $schema->string()->description('Opaque cursor from the previous response\'s nextCursor. Omit for the first page.'),
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
            'query' => ['required', 'string'],
            'kind' => ['nullable', 'string'],
            'cursor' => ['nullable', 'string'],
        ]);
        $kind = ($data['kind'] ?? null) ?: null;
        $position = null;

        if (filled($data['cursor'] ?? null)) {
            $position = $this->position($data['cursor'], $data['query'], $kind);
            if ($position === null) {
                return Response::error('That cursor is not valid for this search.');
            }
        }

        $sources = SlideSource::query()->forTeam($team)->where('archived', false)->with('aliases')
            ->when($kind, fn ($query) => $query->where('kind', $kind))
            ->where(function ($query) use ($data) {
                $query->where('title', 'like', '%'.$data['query'].'%')
                    ->orWhereHas('aliases', fn ($aliases) => $aliases->where('alias', 'like', '%'.$data['query'].'%'));
            })
            ->when($position, function ($query) use ($position) {
                $query->where(function ($query) use ($position) {
                    $query->where('title', '>', $position['title'])
                        ->orWhere(fn ($query) => $query->where('title', $position['title'])->where('id', '>', $position['id']));
                });
            })
            ->orderBy('title')
            ->orderBy('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $hasMore = $sources->count() > self::PAGE_SIZE;
        $page = $sources->take(self::PAGE_SIZE)->map(fn (SlideSource $source) => [
            'id' => $source->id,
            'title' => $source->title,
            'kind' => $source->kind,
            'format' => $source->original_format,
            'aliases' => $source->aliases->pluck('alias')->all(),
        ])->all();

        $result = ['items' => $page];

        if ($hasMore) {
            $last = $sources->values()->get(self::PAGE_SIZE - 1);
            $result['nextCursor'] = $this->cursor($last->title, $last->id, $data['query'], $kind);
        }

        return Response::json($result);
    }

    private function cursor(string $title, int $id, string $query, ?string $kind): string
    {
        return base64_encode((string) json_encode([
            'title' => $title,
            'id' => $id,
            'query' => $query,
            'kind' => $kind,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{title: string, id: int}|null
     */
    private function position(string $cursor, string $query, ?string $kind): ?array
    {
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (! is_array($data) || ! is_string($data['title'] ?? null) || ! is_int($data['id'] ?? null) || $data['id'] < 1) {
            return null;
        }

        if (($data['query'] ?? null) !== $query || ($data['kind'] ?? null) !== $kind) {
            return null;
        }

        return ['title' => $data['title'], 'id' => $data['id']];
    }
}
