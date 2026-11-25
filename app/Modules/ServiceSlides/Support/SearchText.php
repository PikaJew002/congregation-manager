<?php

namespace App\Modules\ServiceSlides\Support;

use Normalizer;

/**
 * Text folded for matching, applied the same way to slide text and to what people type, so MySQL and SQLite agree
 * and nobody has to match case, curly apostrophes, punctuation, accents, or Hebrew vowel points.
 */
class SearchText
{
    public static function normalize(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $text = mb_strtolower($text);
        // The maqaf joins Hebrew words the way a hyphen does, so it splits them like one.
        $text = str_replace(["\u{05BE}", '-', "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}"], ' ', $text);
        // Apostrophes vanish rather than split, so "I'll", "I’ll" and "Ill" all read "ill".
        $text = str_replace(["'", "\u{2019}", "\u{2018}", "\u{02BC}", "\u{05F3}", '`'], '', $text);

        // Accents and niqqud are combining marks once decomposed.
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D) ?: $text;
        $text = preg_replace('/\p{M}+/u', '', $decomposed) ?? $decomposed;
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;

        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * The normalized words of a query, each once.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    /**
     * One searchable string for a whole item: lines on a slide are joined by spaces, so phrases that wrap still
     * match, and slides by newlines.
     *
     * @param  list<list<string>>  $slides
     */
    public static function forSlides(array $slides): string
    {
        return implode("\n", array_map(fn (array $lines) => self::normalize(implode(' ', $lines)), $slides));
    }
}
