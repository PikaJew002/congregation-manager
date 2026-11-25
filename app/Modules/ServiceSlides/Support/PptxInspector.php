<?php

namespace App\Modules\ServiceSlides\Support;

use DOMDocument;

class PptxInspector implements SlideInspector
{
    public const MIMETYPE = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    public function slides(string $path): array
    {
        $package = PptxPackage::open($path);
        $slides = [];

        foreach ($package->slideParts() as $index => $part) {
            $lines = self::lines($package->files[$part]);
            $line = $lines[0] ?? null;

            $slides[] = [
                'index' => $index,
                'label' => $line !== null ? mb_substr($line, 0, 80) : 'Slide '.($index + 1),
                'empty' => self::isBlank($package->files[$part]),
                'line' => $line !== null ? mb_substr($line, 0, 200) : null,
                'lines' => $lines,
            ];
        }

        return $slides;
    }

    public function firstSlideLines(string $path): array
    {
        $package = PptxPackage::open($path);
        $first = $package->slideParts()[0] ?? null;

        return $first !== null ? self::lines($package->files[$first]) : [];
    }

    public function usedSlides(array $slides): array
    {
        while ($slides !== [] && end($slides)['empty']) {
            array_pop($slides);
        }

        return $slides;
    }

    public function pageSize(string $path): ?string
    {
        return self::formatSize(PptxPackage::open($path)->slideSize());
    }

    /**
     * @param  array{cx: int, cy: int}|null  $size
     */
    public static function formatSize(?array $size): ?string
    {
        if ($size === null || $size['cx'] === 0 || $size['cy'] === 0) {
            return null;
        }

        return self::centimeters($size['cx']).'cm x '.self::centimeters($size['cy']).'cm';
    }

    private static function centimeters(int $emu): string
    {
        return rtrim(rtrim(number_format($emu / 360000, 3, '.', ''), '0'), '.');
    }

    /**
     * Non-empty paragraphs of a slide, in document order. Speaker notes live in their own part.
     *
     * @return list<string>
     */
    public static function lines(string $slideXml): array
    {
        $lines = [];

        foreach (self::dom($slideXml)->getElementsByTagNameNS(PptxPackage::DRAWING_NS, 'p') as $paragraph) {
            $text = '';
            foreach ($paragraph->getElementsByTagNameNS(PptxPackage::DRAWING_NS, 't') as $run) {
                $text .= $run->textContent;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    public static function isBlank(string $slideXml): bool
    {
        $dom = self::dom($slideXml);

        if (self::lines($slideXml) !== []) {
            return false;
        }

        return $dom->getElementsByTagNameNS(PptxPackage::PRESENTATION_NS, 'pic')->length === 0
            && $dom->getElementsByTagNameNS(PptxPackage::PRESENTATION_NS, 'graphicFrame')->length === 0
            && $dom->getElementsByTagNameNS(PptxPackage::DRAWING_NS, 'blip')->length === 0;
    }

    private static function dom(string $xml): DOMDocument
    {
        $dom = new DOMDocument;
        if ($xml !== '') {
            $dom->loadXML($xml, LIBXML_NONET);
        }

        return $dom;
    }
}
