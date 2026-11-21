<?php

namespace App\Modules\ServiceSlides\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

class OdpInspector implements SlideInspector
{
    public const MIMETYPE = 'application/vnd.oasis.opendocument.presentation';

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

    public function firstSlideLines(string $path): array
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
        $page = $xpath->query('//draw:page[not(ancestor::draw:page)]')?->item(0);

        if (! $page instanceof DOMElement) {
            return [];
        }

        $lines = [];

        foreach ($xpath->query('.//text:p[not(ancestor::presentation:notes)] | .//text:h[not(ancestor::presentation:notes)]', $page) ?: [] as $paragraph) {
            $text = trim(preg_replace('/\s+/u', ' ', $paragraph->textContent) ?? '');

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
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
        $zip = new ZipArchive;
        $zip->open($path);
        $styles = $zip->getFromName('styles.xml') ?: '';
        $zip->close();

        $dom = new DOMDocument;
        if ($styles === '' || ! @$dom->loadXML($styles)) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('style', 'urn:oasis:names:tc:opendocument:xmlns:style:1.0');
        $xpath->registerNamespace('fo', 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0');

        $layout = $xpath->evaluate('string((//style:master-page)[1]/@style:page-layout-name)');
        $properties = str_contains($layout, "'")
            ? null
            : $xpath->query("//style:page-layout[@style:name='{$layout}']/style:page-layout-properties")->item(0);
        $properties ??= $xpath->query('//style:page-layout-properties')->item(0);

        if (! $properties instanceof DOMElement || ! $properties->hasAttribute('fo:page-width')) {
            return null;
        }

        return $properties->getAttribute('fo:page-width').' x '.$properties->getAttribute('fo:page-height');
    }
}
