<?php

namespace App\Modules\ServiceSlides\Support;

use App\Models\Team;
use App\Modules\ServiceSlides\Models\SlideSource;

/**
 * Finds library items by title, alias, or the words on their slides. Title matches rank first, then aliases, then
 * slide text, and each rank is in title order, so results page with a (rank, title, id) cursor.
 *
 * Slide text matches when every word of the query appears somewhere in the item, after both are folded by
 * SearchText, so line breaks, word order, and punctuation do not get in the way.
 */
class LibrarySearch
{
    public const SCOPES = ['all', 'titles', 'content'];

    private const MATCHES = ['title', 'alias', 'content'];

    private const EXCERPT_LENGTH = 120;

    /**
     * @param  array{rank: int, title: string, id: int}|null  $after
     * @return array{items: list<array<string, mixed>>, last: array{rank: int, title: string, id: int}|null, more: bool}
     */
    public function search(
        Team $team,
        string $query,
        ?string $kind = null,
        string $scope = 'all',
        bool $withArchived = false,
        int $limit = 25,
        ?array $after = null,
    ): array {
        $empty = ['items' => [], 'last' => null, 'more' => false];
        $query = trim($query);
        $words = SearchText::words($query);
        $like = '%'.$this->escapeLike($query).'%';

        $title = ["slide_sources.title like ? escape '!'", [$like]];
        $alias = ["exists (select 1 from slide_source_aliases where slide_source_aliases.slide_source_id = slide_sources.id and slide_source_aliases.alias like ? escape '!')", [$like]];
        $content = $words === [] ? null : [
            implode(' and ', array_fill(0, count($words), 'slide_sources.search_text like ?')),
            array_map(fn (string $word) => '%'.$word.'%', $words),
        ];

        $conditions = match ($scope) {
            'titles' => [$title, $alias],
            'content' => [$content],
            default => [$title, $alias, $content],
        };
        $conditions = array_values(array_filter($conditions));
        if ($query === '' || $conditions === []) {
            return $empty;
        }

        [$rank, $rankBindings] = match ($scope) {
            'content' => ['2', []],
            default => ["case when {$title[0]} then 0 when {$alias[0]} then 1 else 2 end", [...$title[1], ...$alias[1]]],
        };
        $match = implode(' or ', array_map(fn (array $condition) => '('.$condition[0].')', $conditions));
        $matchBindings = array_merge(...array_map(fn (array $condition) => $condition[1], $conditions));

        $rows = SlideSource::query()->forTeam($team)
            ->select(['slide_sources.id', 'slide_sources.title', 'slide_sources.kind', 'slide_sources.original_format', 'slide_sources.slide_text'])
            ->selectRaw("({$rank}) as match_rank", $rankBindings)
            ->with('aliases:id,slide_source_id,alias')
            ->unless($withArchived, fn ($builder) => $builder->where('slide_sources.archived', false))
            ->when($kind, fn ($builder) => $builder->where('slide_sources.kind', $kind))
            ->whereRaw("({$match})", $matchBindings)
            ->when($after, fn ($builder) => $builder->whereRaw(
                "(({$rank}) > ? or (({$rank}) = ? and (slide_sources.title > ? or (slide_sources.title = ? and slide_sources.id > ?))))",
                [...$rankBindings, $after['rank'], ...$rankBindings, $after['rank'], $after['title'], $after['title'], $after['id']],
            ))
            ->orderBy('match_rank')
            ->orderBy('slide_sources.title')
            ->orderBy('slide_sources.id')
            ->limit($limit + 1)
            ->get();

        $more = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $phrase = SearchText::normalize($query);

        $items = [];
        foreach ($page as $source) {
            $rank = (int) $source->getAttribute('match_rank');
            $item = [
                'id' => $source->id,
                'title' => $source->title,
                'kind' => $source->kind,
                'format' => $source->original_format,
                'aliases' => $source->aliases->pluck('alias')->all(),
                'match' => self::MATCHES[$rank],
            ];

            if ($rank === 2 && ($best = $this->bestSlide($source->slide_text ?? [], $words, $phrase))) {
                $item['slide'] = $best['slide'];
                $item['excerpt'] = $best['excerpt'];
            }

            $items[] = $item;
        }

        $last = $page->last();

        return [
            'items' => $items,
            'last' => $last ? ['rank' => (int) $last->getAttribute('match_rank'), 'title' => $last->title, 'id' => $last->id] : null,
            'more' => $more,
        ];
    }

    /**
     * The slide, from 1, and the one or two lines on it that best match: the whole phrase first, then the most words.
     *
     * @param  list<list<string>>  $slides
     * @param  list<string>  $words
     * @return array{slide: int, excerpt: string}|null
     */
    private function bestSlide(array $slides, array $words, string $phrase): ?array
    {
        $best = null;
        $bestScore = 0;

        // Single lines are tried before pairs on every slide, so a pair wins only when it matches more.
        foreach ([1, 2] as $size) {
            foreach ($slides as $index => $lines) {
                $normalized = array_map(fn (string $line) => SearchText::normalize($line), $lines);

                foreach (array_keys($lines) as $start) {
                    if ($start + $size > count($lines)) {
                        continue;
                    }

                    $text = implode(' ', array_slice($normalized, $start, $size));
                    $score = count(array_filter($words, fn (string $word) => str_contains($text, $word)));
                    if ($phrase !== '' && str_contains($text, $phrase)) {
                        $score += count($words) + 1;
                    }

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = ['slide' => $index + 1, 'excerpt' => $this->excerpt(array_slice($lines, $start, $size))];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $lines
     */
    private function excerpt(array $lines): string
    {
        $text = implode(' / ', $lines);

        return mb_strlen($text) > self::EXCERPT_LENGTH ? rtrim(mb_substr($text, 0, self::EXCERPT_LENGTH - 1)).'…' : $text;
    }

    /**
     * Escapes LIKE wildcards with "!", which MySQL and SQLite both accept as an ESCAPE character.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
