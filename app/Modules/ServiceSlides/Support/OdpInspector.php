<?php

namespace App\Modules\ServiceSlides\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

class OdpInspector
{
    public const MIMETYPE = 'application/vnd.oasis.opendocument.presentation';

    /**
     * Returns null when the file is an ODP presentation, or a message explaining why it is not.
     */
    public function rejection(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return 'This is not an ODP file. Save it as ODP in Impress first.';
        }

        $mimetype = $zip->getFromName('mimetype');
        $isPowerPoint = $zip->locateName('[Content_Types].xml') !== false || $zip->locateName('ppt/presentation.xml') !== false;
        $zip->close();

        if ($isPowerPoint) {
            return 'Save as ODP in Impress first.';
        }

        if ($mimetype === false || trim($mimetype) !== self::MIMETYPE) {
            return 'This is not an ODP presentation. Save it as ODP in Impress first.';
        }

        return null;
    }

    /**
     * @return list<array{index: int, label: string, empty: bool, line: string|null}>
     */
    public function slides(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('content.xml') ?: '';
        $zip->close();

        if ($xml === '') {
            return [];
        }

        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('draw', 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0');
        $xpath->registerNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
        $xpath->registerNamespace('presentation', 'urn:oasis:names:tc:opendocument:xmlns:presentation:1.0');

        $slides = [];

        foreach ($xpath->query('//draw:page[not(ancestor::draw:page)]') ?: [] as $index => $page) {
            $lines = [];

            foreach ($xpath->query('.//text:p[not(ancestor::presentation:notes)] | .//text:h[not(ancestor::presentation:notes)]', $page) ?: [] as $paragraph) {
                $text = trim(preg_replace('/\s+/u', ' ', $paragraph->textContent) ?? '');

                if ($text !== '') {
                    $lines[] = $text;
                }
            }

            $hasImage = $page instanceof DOMElement && $xpath->query('.//draw:image', $page)?->length > 0;
            $line = $lines[0] ?? null;

            $slides[] = [
                'index' => $index,
                'label' => $line !== null ? mb_substr($line, 0, 80) : 'Slide '.($index + 1),
                'empty' => $lines === [] && ! $hasImage,
                'line' => $line !== null ? mb_substr($line, 0, 200) : null,
            ];
        }

        return $slides;
    }

    /**
     * Slides the merged deck will use: trailing empty slides are dropped, as OdpDeckBuilder does.
     *
     * @param  list<array{index: int, label: string, empty: bool, line: string|null}>  $slides
     * @return list<array{index: int, label: string, empty: bool, line: string|null}>
     */
    public function usedSlides(array $slides): array
    {
        while ($slides !== [] && end($slides)['empty']) {
            array_pop($slides);
        }

        return $slides;
    }

    public function pageSize(string $path): ?string
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $styles = $zip->getFromName('styles.xml') ?: '';
        $zip->close();

        if (! preg_match('/fo:page-width="([^"]+)".*?fo:page-height="([^"]+)"/s', $styles, $matches)) {
            return null;
        }

        return $matches[1].' x '.$matches[2];
    }
}
