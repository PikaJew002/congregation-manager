<?php

namespace App\Modules\ServiceSlides\Support;

interface SlideInspector
{
    /**
     * @return list<array{index: int, label: string, empty: bool, line: string|null}>
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
     * @param  list<array{index: int, label: string, empty: bool, line: string|null}>  $slides
     * @return list<array{index: int, label: string, empty: bool, line: string|null}>
     */
    public function usedSlides(array $slides): array;

    public function pageSize(string $path): ?string;
}
