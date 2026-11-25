<?php

namespace App\Modules\ServiceSlides\Mcp\Tools;

use App\Mcp\CongregationContext;
use App\Mcp\ModuleTool;
use App\Modules\ServiceSlides\Support\LibrarySearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SearchLibrary extends Tool
{
    use ModuleTool;

    private const PAGE_SIZE = 25;

    protected string $description = 'Search the congregation slide library by title, alias, or the words on the slides, such as a remembered lyric or line of liturgy. Title matches come first, then aliases, then slide text; a slide text match gives the slide number (from 1) and an excerpt. Each page has at most 25 matches. When the response includes nextCursor, call again with that value as cursor and the same query, kind, and scope.';

    public function __construct(private CongregationContext $context, private LibrarySearch $search) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Title, alias, or words on the slides to search for. Every word must appear in an item for a slide text match; order, case, and punctuation do not matter.')->required(),
            'kind' => $schema->string()->description('Optional kind: song, liturgy, or other.'),
            'scope' => $schema->string()->enum(LibrarySearch::SCOPES)->description('Optional: all (default) searches titles, aliases, and slide text; titles searches titles and aliases only; content searches slide text only.'),
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
            'scope' => ['nullable', 'string', Rule::in(LibrarySearch::SCOPES)],
            'cursor' => ['nullable', 'string'],
        ]);
        $kind = ($data['kind'] ?? null) ?: null;
        $scope = ($data['scope'] ?? null) ?: 'all';
        $position = null;

        if (filled($data['cursor'] ?? null)) {
            $position = $this->position($data['cursor'], $data['query'], $kind, $scope);
            if ($position === null) {
                return Response::error('That cursor is not valid for this search.');
            }
        }

        $found = $this->search->search($team, $data['query'], $kind, $scope, limit: self::PAGE_SIZE, after: $position);
        $result = ['items' => $found['items']];

        if ($found['more'] && $found['last']) {
            $result['nextCursor'] = $this->cursor($found['last'], $data['query'], $kind, $scope);
        }

        return Response::json($result);
    }

    /**
     * @param  array{rank: int, title: string, id: int}  $last
     */
    private function cursor(array $last, string $query, ?string $kind, string $scope): string
    {
        return base64_encode((string) json_encode([
            ...$last,
            'query' => $query,
            'kind' => $kind,
            'scope' => $scope,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{rank: int, title: string, id: int}|null
     */
    private function position(string $cursor, string $query, ?string $kind, string $scope): ?array
    {
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (! is_array($data) || ! is_string($data['title'] ?? null) || ! is_int($data['id'] ?? null) || $data['id'] < 1
            || ! is_int($data['rank'] ?? null) || $data['rank'] < 0 || $data['rank'] > 2) {
            return null;
        }

        if (($data['query'] ?? null) !== $query || ($data['kind'] ?? null) !== $kind || ($data['scope'] ?? null) !== $scope) {
            return null;
        }

        return ['rank' => $data['rank'], 'title' => $data['title'], 'id' => $data['id']];
    }
}
