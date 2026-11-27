<?php

namespace App\Modules\ServiceSlides\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
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

        foreach ($this->elements($xpath, '//draw:page[not(ancestor::draw:page)]') as $index => $page) {
            $lines = $this->lines($xpath, $page);
            $hasImage = $this->elements($xpath, './/draw:image', $page) !== [];
            $line = $lines[0] ?? null;

            $slides[] = [
                'index' => $index,
                'label' => $line !== null ? mb_substr($line, 0, 80) : 'Slide '.($index + 1),
                'empty' => $lines === [] && ! $hasImage,
                'line' => $line !== null ? mb_substr($line, 0, 200) : null,
                'lines' => $lines,
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
        $page = $this->elements($xpath, '//draw:page[not(ancestor::draw:page)]')[0] ?? null;

        return $page === null ? [] : $this->lines($xpath, $page);
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
            : $this->elements($xpath, "//style:page-layout[@style:name='{$layout}']/style:page-layout-properties")[0] ?? null;
        $properties ??= $this->elements($xpath, '//style:page-layout-properties')[0] ?? null;

        if ($properties === null || ! $properties->hasAttribute('fo:page-width')) {
            return null;
        }

        return $properties->getAttribute('fo:page-width').' x '.$properties->getAttribute('fo:page-height');
    }

    /**
     * The slide's paragraph and heading text, leaving out speaker notes.
     *
     * @return list<string>
     */
    private function lines(DOMXPath $xpath, DOMElement $page): array
    {
        $lines = [];

        foreach ($this->elements($xpath, './/text:p[not(ancestor::presentation:notes)] | .//text:h[not(ancestor::presentation:notes)]', $page) as $paragraph) {
            $text = trim(preg_replace('/\s+/u', ' ', $paragraph->textContent) ?? '');

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $nodes = $xpath->query($expression, $context);

        if ($nodes === false) {
            throw new RuntimeException("Invalid XPath expression: {$expression}");
        }

        return array_values(array_filter(iterator_to_array($nodes), fn ($node) => $node instanceof DOMElement));
    }
}
