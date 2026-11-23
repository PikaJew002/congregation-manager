<?php

namespace App\Modules\ServiceSlides\Support;

interface DeckBuilder
{
    /**
     * @param  list<array{type: string, index?: int, path?: string, label?: string, slides?: list<int>}>  $entries
     * @return list<string>
     */
    public function build(string $templatePath, array $entries, string $destination): array;
}
