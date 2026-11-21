<?php

namespace App\Modules\ServiceSlides\Support;

use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

trait OdpDocument
{
    private const DRAW = 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0';

    private const STYLE = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';

    private const OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    private const XLINK = 'http://www.w3.org/1999/xlink';

    private const SVG = 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0';

    private const FO = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';

    private const PRESENTATION = 'urn:oasis:names:tc:opendocument:xmlns:presentation:1.0';

    private const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    private const OFFICE_SECTIONS = ['scripts', 'font-face-decls', 'styles', 'automatic-styles', 'master-styles', 'body'];

    /**
     * @return list<DOMElement>
     */
    private function pages(DOMDocument $dom): array
    {
        $pages = [];
        foreach ($dom->getElementsByTagNameNS(self::DRAW, 'page') as $page) {
            if ($page->parentNode?->localName === 'page') {
                continue;
            }
            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * @return array<string, list<DOMElement>>
     */
    private function styleIndex(DOMDocument ...$documents): array
    {
        $index = [];
        foreach ($documents as $dom) {
            foreach ($dom->getElementsByTagNameNS(self::STYLE, 'style') as $style) {
                $index[$style->getAttributeNS(self::STYLE, 'name')][] = $style;
            }
        }

        return $index;
    }

    /**
     * @return list<DOMElement>
     */
    private function slideElements(DOMElement $page): array
    {
        $elements = [];
        foreach (iterator_to_array($page->getElementsByTagName('*')) as $element) {
            for ($node = $element; $node !== $page; $node = $node->parentNode) {
                if ($node->localName === 'notes') {
                    continue 2;
                }
            }
            $elements[] = $element;
        }

        return $elements;
    }

    private function child(DOMElement $parent, string $namespace, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === $namespace && $child->localName === $localName) {
                return $child;
            }
        }

        return null;
    }

    private function section(DOMDocument $dom, string $localName): DOMElement
    {
        $existing = $this->first($dom, self::OFFICE, $localName);
        if ($existing) {
            return $existing;
        }

        $root = $dom->documentElement;
        $section = $dom->createElementNS(self::OFFICE, 'office:'.$localName);
        $later = array_slice(self::OFFICE_SECTIONS, array_search($localName, self::OFFICE_SECTIONS, true) + 1);

        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === self::OFFICE && in_array($child->localName, $later, true)) {
                return $root->insertBefore($section, $child);
            }
        }

        return $root->appendChild($section);
    }

    /**
     * @return array{width: float, height: float}|null
     */
    private function pageSize(DOMDocument $styles): ?array
    {
        $layout = $this->slideLayout($styles)?->getElementsByTagNameNS(self::STYLE, 'page-layout-properties')->item(0);

        if (! $layout instanceof DOMElement) {
            return null;
        }

        $width = $this->toCentimeters($layout->getAttributeNS(self::FO, 'page-width'));
        $height = $this->toCentimeters($layout->getAttributeNS(self::FO, 'page-height'));

        if ($width === null || $height === null) {
            return null;
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Notes and handout pages have their own page layouts, often listed first; slides use the master page's layout.
     */
    private function slideLayout(DOMDocument $styles): ?DOMElement
    {
        $layouts = iterator_to_array($styles->getElementsByTagNameNS(self::STYLE, 'page-layout'));
        $master = $this->first($styles, self::STYLE, 'master-page');
        $name = $master?->getAttributeNS(self::STYLE, 'page-layout-name');

        foreach ($layouts as $layout) {
            if ($layout->getAttributeNS(self::STYLE, 'name') === $name) {
                return $layout;
            }
        }

        return $layouts[0] ?? null;
    }

    private function toCentimeters(string $value): ?float
    {
        if (! preg_match('/^(-?\d+(?:\.\d+)?)(cm|in|mm|pt)$/', $value, $matches)) {
            return null;
        }

        $number = (float) $matches[1];

        return match ($matches[2]) {
            'cm' => $number,
            'mm' => $number / 10,
            'in' => $number * 2.54,
            'pt' => $number * 2.54 / 72,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function readZip(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open {$path}.");
        }

        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (str_ends_with($name, '/')) {
                continue;
            }
            $files[$name] = $zip->getFromIndex($index);
        }
        $zip->close();

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function writeZip(string $destination, array $files): void
    {
        if (is_file($destination)) {
            unlink($destination);
        }

        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Unable to write {$destination}.");
        }

        $mimetype = $files['mimetype'] ?? 'application/vnd.oasis.opendocument.presentation';
        $zip->addFromString('mimetype', $mimetype);
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        unset($files['mimetype']);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
    }

    private function dom(string $xml): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->loadXML($xml);

        return $dom;
    }

    private function first(DOMDocument $dom, string $namespace, string $localName): ?DOMElement
    {
        $node = $dom->getElementsByTagNameNS($namespace, $localName)->item(0);

        return $node instanceof DOMElement ? $node : null;
    }
}
