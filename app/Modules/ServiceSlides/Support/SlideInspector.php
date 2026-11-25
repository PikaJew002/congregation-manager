<?php

namespace App\Modules\ServiceSlides\Support;

interface SlideInspector
{
    /**
     * Each slide's text, ignoring speaker notes: `line` is the first line cut to 200 characters, `lines` every line.
     *
     * @return list<array{index: int, label: string, empty: bool, line: string|null, lines: list<string>}>
     */
    public function slides(string $path): array;

    /**
     * Non-empty text lines on the first slide, ignoring speaker notes.
     *
     * @return list<string>
     */
    public function firstSlideLines(string $path): array;

    /**
     * Slides the merged deck will use: trailing empty slides are dropped, as the deck builders do.
     *
     * @param  list<array{index: int, label: string, empty: bool, line: string|null, lines: list<string>}>  $slides
     * @return list<array{index: int, label: string, empty: bool, line: string|null, lines: list<string>}>
     */
    public function usedSlides(array $slides): array;

    public function pageSize(string $path): ?string;
}
