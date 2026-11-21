<?php

namespace App\Modules\ServiceSlides\Support;

use DOMDocument;
use DOMElement;

class OdpDeckBuilder implements DeckBuilder
{
    use OdpDocument;

    private const NUMBER = 'urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0';

    private const XML = 'http://www.w3.org/XML/1998/namespace';

    private const ID_ATTRIBUTES = [self::DRAW => 'draw:id', self::XML => 'xml:id'];

    private const ID_REFERENCES = ['start-shape', 'end-shape', 'targetElement', 'shape-id'];

    public function build(string $templatePath, array $entries, string $destination): array
    {
        $files = $this->readZip($templatePath);
        $content = $this->dom($files['content.xml']);
        $styles = $this->dom($files['styles.xml']);
        $warnings = [];
        $templateSize = $this->pageSize($styles);
        $imageHashes = $this->hashExistingMedia($files);
        $masterHashes = [];
        $pages = [];

        foreach ($entries as $index => $entry) {
            if ($entry['type'] === 'template_slide') {
                $sourcePages = $this->pages($content);
                $slideIndex = $entry['index'] ?? 0;
                if (! isset($sourcePages[$slideIndex])) {
                    $warnings[] = "Template slide {$slideIndex} does not exist.";

                    continue;
                }
                $pages[] = $sourcePages[$slideIndex]->cloneNode(true);

                continue;
            }

            if ($entry['type'] !== 'source') {
                $warnings[] = 'Unknown deck entry.';

                continue;
            }

            $imported = $this->importSource(
                $entry['path'],
                $content,
                $styles,
                $files,
                $templateSize,
                $imageHashes,
                $masterHashes,
                'i'.$index.'_',
                $entry['slides'] ?? null,
            );
            $warnings = [...$warnings, ...$imported['warnings']];
            array_push($pages, ...$imported['pages']);
        }

        $this->replacePages($content, $pages);
        $this->uniquifyIds($content, $styles);
        $files['content.xml'] = $content->saveXML();
        $files['styles.xml'] = $styles->saveXML();
        $files['META-INF/manifest.xml'] = $this->manifest($files);
        $this->writeZip($destination, $files);

        return $warnings;
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, string>  $imageHashes
     * @param  array<string, string>  $masterHashes
     * @param  list<int>|null  $onlySlides
     * @return array{pages: list<DOMElement>, warnings: list<string>}
     */
    private function importSource(
        string $path,
        DOMDocument $content,
        DOMDocument $styles,
        array &$files,
        ?array $templateSize,
        array &$imageHashes,
        array &$masterHashes,
        string $prefix,
        ?array $onlySlides,
    ): array {
        $warnings = [];
        $sourceFiles = $this->readZip($path);
        $sourceContent = $this->dom($sourceFiles['content.xml']);
        $sourceStyles = $this->dom($sourceFiles['styles.xml']);
        $sourceSize = $this->pageSize($sourceStyles);
        $scale = $this->scale($templateSize, $sourceSize, $warnings, $path);

        $selected = $this->selectedPages($sourceContent, $onlySlides);
        $masterContentHashes = $this->masterContentHashes($sourceStyles);
        $names = $this->collectNames($sourceContent, $sourceStyles);
        $renames = [];
        foreach ($names as $name) {
            $renames[$name] = $prefix.$name;
        }

        $this->renameDefinedNames($sourceContent, $renames);
        $this->renameDefinedNames($sourceStyles, $renames);
        $this->rewriteReferences($sourceContent, $renames);
        $this->rewriteReferences($sourceStyles, $renames);

        if ($scale !== null && abs($scale - 1) > 0.01) {
            $this->scaleGeometry($sourceContent, $scale);
            $this->scaleGeometry($sourceStyles, $scale);
        }

        $this->rewriteImages($sourceContent, $sourceFiles, $files, $imageHashes);
        $this->rewriteImages($sourceStyles, $sourceFiles, $files, $imageHashes);
        $this->importAutomaticStyles($sourceContent, $content);
        $this->importAutomaticStyles($sourceStyles, $styles);
        $this->importCommonStyles($sourceStyles, $styles);
        $this->importMasters($sourceContent, $sourceStyles, $styles, $masterHashes, $masterContentHashes, $prefix, $this->pageLayoutName($styles));
        $this->importFonts($sourceContent, $content);
        $this->importFonts($sourceStyles, $styles);

        $pages = [];
        foreach ($this->pages($sourceContent) as $page) {
            if (! in_array($page, $selected, true)) {
                continue;
            }
            $pages[] = $content->importNode($page, true);
        }

        return ['pages' => $pages, 'warnings' => $warnings];
    }

    /**
     * @param  list<int>|null  $only
     * @return list<DOMElement>
     */
    private function selectedPages(DOMDocument $dom, ?array $only): array
    {
        $pages = $this->pages($dom);

        if ($only !== null) {
            return array_values(array_filter(
                $pages,
                fn (DOMElement $page, int $index) => in_array($index, $only, true),
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        while ($pages !== [] && $this->isEmpty(end($pages))) {
            array_pop($pages);
        }

        return $pages;
    }

    private function isEmpty(DOMElement $page): bool
    {
        if (trim($page->textContent) !== '') {
            return false;
        }

        return $page->getElementsByTagNameNS(self::DRAW, 'image')->length === 0;
    }

    /**
     * @return list<string>
     */
    private function collectNames(DOMDocument $content, DOMDocument $styles): array
    {
        $names = [];
        foreach ([$content, $styles] as $dom) {
            foreach ($dom->getElementsByTagName('*') as $element) {
                if (! $element instanceof DOMElement || ! $attribute = $this->nameAttribute($element)) {
                    continue;
                }
                $name = $element->getAttributeNS($attribute[0], 'name');
                if ($name !== '') {
                    $names[$name] = $name;
                }
            }
        }

        return array_values($names);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function nameAttribute(DOMElement $element): ?array
    {
        return match (true) {
            $element->namespaceURI === self::STYLE
                && in_array($element->localName, ['style', 'master-page', 'page-layout', 'font-face', 'presentation-page-layout'], true),
            $element->namespaceURI === self::TEXT && $element->localName === 'list-style',
            $element->namespaceURI === self::NUMBER && str_ends_with($element->localName, '-style') => [self::STYLE, 'style:name'],
            $element->namespaceURI === self::DRAW
                && in_array($element->localName, ['gradient', 'hatch', 'fill-image', 'marker', 'stroke-dash', 'opacity'], true),
            $element->namespaceURI === self::SVG
                && in_array($element->localName, ['linearGradient', 'radialGradient'], true) => [self::DRAW, 'draw:name'],
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function renameDefinedNames(DOMDocument $dom, array $renames): void
    {
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement || ! $attribute = $this->nameAttribute($element)) {
                continue;
            }
            $name = $element->getAttributeNS($attribute[0], 'name');
            if (isset($renames[$name])) {
                $element->setAttributeNS($attribute[0], $attribute[1], $renames[$name]);
            }
        }
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function rewriteReferences(DOMDocument $dom, array $renames): void
    {
        $referenceAttributes = [
            'style-name',
            'text-style-name',
            'master-page-name',
            'parent-style-name',
            'page-layout-name',
            'fill-image-name',
            'fill-gradient-name',
            'fill-hatch-name',
            'marker-start',
            'marker-end',
            'stroke-dash',
            'opacity-name',
            'list-style-name',
            'font-name',
            'data-style-name',
            'presentation-page-layout-name',
        ];

        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement || ! $element->hasAttributes()) {
                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (! in_array($attribute->localName, $referenceAttributes, true)) {
                    continue;
                }
                if (isset($renames[$attribute->value])) {
                    $element->setAttributeNS($attribute->namespaceURI, $attribute->nodeName, $renames[$attribute->value]);
                }
            }
        }
    }

    private function importAutomaticStyles(DOMDocument $source, DOMDocument $target): void
    {
        $sourceStyles = $this->first($source, self::OFFICE, 'automatic-styles');
        $targetStyles = $this->first($target, self::OFFICE, 'automatic-styles');

        if (! $sourceStyles || ! $targetStyles) {
            return;
        }

        foreach (iterator_to_array($sourceStyles->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $targetStyles->appendChild($target->importNode($child, true));
            }
        }
    }

    private function importCommonStyles(DOMDocument $source, DOMDocument $target): void
    {
        $sourceStyles = $this->first($source, self::OFFICE, 'styles');

        if (! $sourceStyles) {
            return;
        }

        $targetStyles = $this->section($target, 'styles');
        foreach (iterator_to_array($sourceStyles->childNodes) as $child) {
            if ($child instanceof DOMElement && $this->nameAttribute($child)) {
                $targetStyles->appendChild($target->importNode($child, true));
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function masterContentHashes(DOMDocument $styles): array
    {
        $hashes = [];
        $masters = $this->first($styles, self::OFFICE, 'master-styles');

        if (! $masters) {
            return $hashes;
        }

        foreach ($masters->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'master-page') {
                $name = $child->getAttributeNS(self::STYLE, 'name');
                $hashes[$name] = hash('sha256', $child->C14N());
            }
        }

        return $hashes;
    }

    /**
     * @param  array<string, string>  $masterHashes
     * @param  array<string, string>  $masterContentHashes
     */
    private function importMasters(
        DOMDocument $sourceContent,
        DOMDocument $sourceStyles,
        DOMDocument $targetStyles,
        array &$masterHashes,
        array $masterContentHashes,
        string $prefix,
        ?string $pageLayout,
    ): void {
        $sourceMasters = $this->first($sourceStyles, self::OFFICE, 'master-styles');
        $targetMasters = $this->first($targetStyles, self::OFFICE, 'master-styles');

        if (! $sourceMasters || ! $targetMasters) {
            return;
        }

        foreach (iterator_to_array($sourceMasters->childNodes) as $child) {
            if (! $child instanceof DOMElement || $child->localName !== 'master-page') {
                continue;
            }

            $name = $child->getAttributeNS(self::STYLE, 'name');
            $original = str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
            $hash = $masterContentHashes[$original] ?? hash('sha256', $child->C14N());

            if (isset($masterHashes[$hash])) {
                $this->replaceMasterName($sourceContent, $name, $masterHashes[$hash]);
                $this->replaceMasterName($sourceStyles, $name, $masterHashes[$hash]);

                continue;
            }

            if ($pageLayout) {
                $child->setAttributeNS(self::STYLE, 'style:page-layout-name', $pageLayout);
            }

            $masterHashes[$hash] = $name;
            $targetMasters->appendChild($targetStyles->importNode($child, true));
        }
    }

    private function replaceMasterName(DOMDocument $dom, string $from, string $to): void
    {
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            $value = $element->getAttributeNS(self::DRAW, 'master-page-name');
            if ($value === $from) {
                $element->setAttributeNS(self::DRAW, 'draw:master-page-name', $to);
            }
            $value = $element->getAttributeNS(self::STYLE, 'master-page-name');
            if ($value === $from) {
                $element->setAttributeNS(self::STYLE, 'style:master-page-name', $to);
            }
        }
    }

    private function importFonts(DOMDocument $source, DOMDocument $target): void
    {
        $sourceFonts = $this->first($source, self::OFFICE, 'font-face-decls');

        if (! $sourceFonts) {
            return;
        }

        $targetFonts = $this->section($target, 'font-face-decls');
        $existing = [];
        foreach ($targetFonts->getElementsByTagNameNS(self::STYLE, 'font-face') as $font) {
            $existing[$font->getAttributeNS(self::STYLE, 'name')] = true;
        }

        foreach (iterator_to_array($sourceFonts->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $name = $child->getAttributeNS(self::STYLE, 'name');
            if ($name === '' || isset($existing[$name])) {
                continue;
            }
            $targetFonts->appendChild($target->importNode($child, true));
            $existing[$name] = true;
        }
    }

    /**
     * @param  array<string, string>  $sourceFiles
     * @param  array<string, string>  $files
     * @param  array<string, string>  $imageHashes
     */
    private function rewriteImages(DOMDocument $dom, array $sourceFiles, array &$files, array &$imageHashes): void
    {
        $images = [
            ...iterator_to_array($dom->getElementsByTagNameNS(self::DRAW, 'image')),
            ...iterator_to_array($dom->getElementsByTagNameNS(self::DRAW, 'fill-image')),
        ];

        foreach ($images as $image) {
            $href = $image->getAttributeNS(self::XLINK, 'href');
            if ($href === '' || ! isset($sourceFiles[$href])) {
                continue;
            }

            $hash = hash('sha256', $sourceFiles[$href]);
            if (! isset($imageHashes[$hash])) {
                $extension = pathinfo($href, PATHINFO_EXTENSION) ?: 'bin';
                $target = 'Pictures/merged_'.substr($hash, 0, 16).'.'.$extension;
                $files[$target] = $sourceFiles[$href];
                $imageHashes[$hash] = $target;
            }

            $image->setAttributeNS(self::XLINK, 'xlink:href', $imageHashes[$hash]);
        }
    }

    /**
     * @param  list<DOMElement>  $pages
     */
    private function replacePages(DOMDocument $content, array $pages): void
    {
        $existing = $this->pages($content);
        $parent = $existing[0]->parentNode ?? $this->first($content, self::OFFICE, 'presentation');
        $settings = $parent ? $this->first($content, self::PRESENTATION, 'settings') : null;

        foreach ($existing as $page) {
            $page->parentNode?->removeChild($page);
        }

        // PowerPoint ignores every draw:page that follows presentation:settings.
        $before = $settings?->parentNode === $parent ? $settings : null;

        foreach (array_values($pages) as $index => $page) {
            if ($page->ownerDocument !== $content) {
                $page = $content->importNode($page, true);
            }
            $page->setAttributeNS(self::DRAW, 'draw:name', 'page'.($index + 1));
            $parent?->insertBefore($page, $before);
        }
    }

    private function uniquifyIds(DOMDocument $content, DOMDocument $styles): void
    {
        $used = [];
        $containers = [
            ...iterator_to_array($styles->getElementsByTagNameNS(self::STYLE, 'master-page')),
            ...$this->pages($content),
        ];

        foreach ($containers as $container) {
            $elements = [$container, ...iterator_to_array($container->getElementsByTagName('*'))];
            $renames = [];

            foreach ($elements as $element) {
                foreach (self::ID_ATTRIBUTES as $namespace => $qualified) {
                    $id = $element->getAttributeNS($namespace, 'id');
                    if ($id === '') {
                        continue;
                    }
                    $renames[$id] ??= $this->freshId($id, $used);
                    if ($renames[$id] !== $id) {
                        $element->setAttributeNS($namespace, $qualified, $renames[$id]);
                    }
                }
            }

            foreach ($elements as $element) {
                foreach (iterator_to_array($element->attributes) as $attribute) {
                    $renamed = $renames[$attribute->value] ?? null;
                    if ($renamed !== null && $renamed !== $attribute->value && in_array($attribute->localName, self::ID_REFERENCES, true)) {
                        $element->setAttributeNS($attribute->namespaceURI, $attribute->nodeName, $renamed);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, true>  $used
     */
    private function freshId(string $id, array &$used): string
    {
        $candidate = $id;
        for ($suffix = 2; isset($used[$candidate]); $suffix++) {
            $candidate = $id.'-'.$suffix;
        }
        $used[$candidate] = true;

        return $candidate;
    }

    private function scaleGeometry(DOMDocument $dom, float $scale): void
    {
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            foreach (['x', 'y', 'width', 'height'] as $attribute) {
                $value = $element->getAttributeNS(self::SVG, $attribute);
                if ($value === '' || ! preg_match('/^(-?\d+(?:\.\d+)?)(cm|in|mm|pt)$/', $value, $matches)) {
                    continue;
                }
                $scaled = round(((float) $matches[1]) * $scale, 4);
                $element->setAttributeNS(self::SVG, 'svg:'.$attribute, $scaled.$matches[2]);
            }
        }
    }

    /**
     * @param  array{width: float, height: float}|null  $template
     * @param  array{width: float, height: float}|null  $source
     * @param  list<string>  $warnings
     */
    private function scale(?array $template, ?array $source, array &$warnings, string $path): ?float
    {
        if (! $template || ! $source || $source['width'] == 0.0 || $source['height'] == 0.0) {
            $warnings[] = 'Could not compare page size for '.basename($path).'.';

            return null;
        }

        return min($template['width'] / $source['width'], $template['height'] / $source['height']);
    }

    private function pageLayoutName(DOMDocument $styles): ?string
    {
        return $this->slideLayout($styles)?->getAttributeNS(self::STYLE, 'name');
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private function hashExistingMedia(array $files): array
    {
        $hashes = [];
        foreach ($files as $name => $contents) {
            if (str_starts_with($name, 'Pictures/') || str_starts_with($name, 'media/')) {
                $hashes[hash('sha256', $contents)] = $name;
            }
        }

        return $hashes;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function manifest(array $files): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElementNS('urn:oasis:names:tc:opendocument:xmlns:manifest:1.0', 'manifest:manifest');
        $root->setAttributeNS('urn:oasis:names:tc:opendocument:xmlns:manifest:1.0', 'manifest:version', '1.2');
        $dom->appendChild($root);

        foreach (array_keys($files) as $name) {
            if ($name === 'META-INF/manifest.xml') {
                continue;
            }
            $entry = $dom->createElementNS('urn:oasis:names:tc:opendocument:xmlns:manifest:1.0', 'manifest:file-entry');
            $entry->setAttributeNS('urn:oasis:names:tc:opendocument:xmlns:manifest:1.0', 'manifest:full-path', $name === 'mimetype' ? '/' : $name);
            $entry->setAttributeNS('urn:oasis:names:tc:opendocument:xmlns:manifest:1.0', 'manifest:media-type', $this->mediaType($name));
            $root->appendChild($entry);
        }

        return $dom->saveXML();
    }

    private function mediaType(string $name): string
    {
        return match (true) {
            $name === 'mimetype' => 'application/vnd.oasis.opendocument.presentation',
            str_ends_with($name, '.xml') => 'text/xml',
            str_ends_with($name, '.png') => 'image/png',
            str_ends_with($name, '.jpg'), str_ends_with($name, '.jpeg') => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
