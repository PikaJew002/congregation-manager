<?php

namespace App\Modules\ServiceSlides\Support;

use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

/**
 * The parts of an Office Open XML presentation, with their relationships and content types.
 * Part names are absolute paths inside the zip, without a leading slash.
 */
class PptxPackage
{
    public const PRESENTATION_NS = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    public const DRAWING_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    public const RELATIONSHIPS_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public const MAIN_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml';

    private const PACKAGE_RELATIONSHIPS_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const CONTENT_TYPES_NS = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /** @var array<string, string> */
    public array $defaults = [];

    /** @var array<string, string> */
    public array $overrides = [];

    /**
     * @param  array<string, string>  $files
     */
    public function __construct(public array $files)
    {
        $types = $this->dom($files['[Content_Types].xml'] ?? '<Types xmlns="'.self::CONTENT_TYPES_NS.'"/>');

        foreach ($types->getElementsByTagNameNS(self::CONTENT_TYPES_NS, 'Default') as $default) {
            $this->defaults[strtolower($default->getAttribute('Extension'))] = $default->getAttribute('ContentType');
        }

        foreach ($types->getElementsByTagNameNS(self::CONTENT_TYPES_NS, 'Override') as $override) {
            $this->overrides[ltrim($override->getAttribute('PartName'), '/')] = $override->getAttribute('ContentType');
        }
    }

    public static function open(string $path): self
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to open {$path}.");
        }

        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $contents = $zip->getFromIndex($index);
            if ($name !== false && $contents !== false && ! str_ends_with($name, '/')) {
                $files[$name] = $contents;
            }
        }
        $zip->close();

        return new self($files);
    }

    public function presentationPart(): string
    {
        foreach ($this->rels('') as $rel) {
            if ($rel['type'] === 'officeDocument') {
                return $rel['target'];
            }
        }

        return 'ppt/presentation.xml';
    }

    /**
     * Slide parts in show order.
     *
     * @return list<string>
     */
    public function slideParts(): array
    {
        $part = $this->presentationPart();
        $targets = array_column($this->rels($part), 'target', 'id');
        $slides = [];

        foreach ($this->dom($this->files[$part])->getElementsByTagNameNS(self::PRESENTATION_NS, 'sldId') as $slide) {
            $target = $targets[$slide->getAttributeNS(self::RELATIONSHIPS_NS, 'id')] ?? null;
            if ($target !== null && isset($this->files[$target])) {
                $slides[] = $target;
            }
        }

        return $slides;
    }

    /**
     * @return array{cx: int, cy: int}|null
     */
    public function slideSize(): ?array
    {
        $size = $this->dom($this->files[$this->presentationPart()] ?? '')->getElementsByTagNameNS(self::PRESENTATION_NS, 'sldSz')->item(0);

        if (! $size instanceof DOMElement) {
            return null;
        }

        return ['cx' => (int) $size->getAttribute('cx'), 'cy' => (int) $size->getAttribute('cy')];
    }

    /**
     * Relationships of a part; pass '' for the package root. Internal targets are resolved to part names,
     * and `type` is the last segment of the relationship type URI.
     *
     * @return list<array{id: string, type: string, uri: string, target: string, external: bool}>
     */
    public function rels(string $part): array
    {
        $xml = $this->files[$this->relsPath($part)] ?? null;
        if ($xml === null) {
            return [];
        }

        $rels = [];
        foreach ($this->dom($xml)->getElementsByTagNameNS(self::PACKAGE_RELATIONSHIPS_NS, 'Relationship') as $rel) {
            $external = $rel->getAttribute('TargetMode') === 'External';
            $uri = $rel->getAttribute('Type');
            $rels[] = [
                'id' => $rel->getAttribute('Id'),
                'type' => substr($uri, (int) strrpos($uri, '/') + 1),
                'uri' => $uri,
                'target' => $external ? $rel->getAttribute('Target') : $this->resolve($part, $rel->getAttribute('Target')),
                'external' => $external,
            ];
        }

        return $rels;
    }

    /**
     * @param  list<array{id: string, uri: string, target: string, external: bool}>  $rels
     */
    public function writeRels(string $part, array $rels): void
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->xmlStandalone = true;
        $root = $dom->appendChild($dom->createElementNS(self::PACKAGE_RELATIONSHIPS_NS, 'Relationships'));

        foreach ($rels as $rel) {
            $element = $dom->createElementNS(self::PACKAGE_RELATIONSHIPS_NS, 'Relationship');
            $element->setAttribute('Id', $rel['id']);
            $element->setAttribute('Type', $rel['uri']);
            $element->setAttribute('Target', $rel['external'] ? $rel['target'] : $this->relative($part, $rel['target']));
            if ($rel['external']) {
                $element->setAttribute('TargetMode', 'External');
            }
            $root->appendChild($element);
        }

        $this->files[$this->relsPath($part)] = $this->xml($dom);
    }

    public function relsPath(string $part): string
    {
        if ($part === '') {
            return '_rels/.rels';
        }

        $directory = dirname($part);

        return ($directory === '.' ? '' : $directory.'/').'_rels/'.basename($part).'.rels';
    }

    public function contentType(string $part): ?string
    {
        return $this->overrides[$part] ?? $this->defaults[strtolower(pathinfo($part, PATHINFO_EXTENSION))] ?? null;
    }

    public function setContentType(string $part, ?string $type): void
    {
        if ($type === null) {
            return;
        }

        $extension = strtolower(pathinfo($part, PATHINFO_EXTENSION));
        $default = $this->defaults[$extension] ?? null;

        if ($default === $type) {
            unset($this->overrides[$part]);
        } elseif ($default === null && ! str_ends_with($type, '+xml')) {
            $this->defaults[$extension] = $type;
        } else {
            $this->overrides[$part] = $type;
        }
    }

    /**
     * An unused part name in the same folder, numbered like PowerPoint's own (slide1.xml, image2.png).
     */
    public function freshName(string $like): string
    {
        $directory = dirname($like);
        $extension = pathinfo($like, PATHINFO_EXTENSION);
        $stem = preg_replace('/\d+$/', '', pathinfo($like, PATHINFO_FILENAME)) ?: 'part';

        for ($number = 1; ; $number++) {
            $name = "{$directory}/{$stem}{$number}".($extension !== '' ? '.'.$extension : '');
            if (! isset($this->files[$name])) {
                return $name;
            }
        }
    }

    /**
     * Drops parts that nothing links to any more, and content type overrides for parts that are gone.
     */
    public function prune(): void
    {
        $reachable = [];
        $queue = [''];

        while ($queue !== []) {
            $part = array_pop($queue);
            foreach ($this->rels($part) as $rel) {
                if (! $rel['external'] && ! isset($reachable[$rel['target']]) && isset($this->files[$rel['target']])) {
                    $reachable[$rel['target']] = true;
                    $queue[] = $rel['target'];
                }
            }
        }

        $keep = ['[Content_Types].xml' => true, '_rels/.rels' => true];
        foreach (array_keys($reachable) as $part) {
            $keep[$part] = true;
            $keep[$this->relsPath($part)] = true;
        }

        $this->files = array_intersect_key($this->files, $keep);
        $this->overrides = array_intersect_key($this->overrides, $this->files);
    }

    public function save(string $destination): void
    {
        $this->files['[Content_Types].xml'] = $this->contentTypesXml();

        if (is_file($destination)) {
            unlink($destination);
        }

        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Unable to write {$destination}.");
        }

        $zip->addFromString('[Content_Types].xml', $this->files['[Content_Types].xml']);
        foreach ($this->files as $name => $contents) {
            if ($name !== '[Content_Types].xml') {
                $zip->addFromString($name, $contents);
            }
        }

        $zip->close();
    }

    public function dom(string $xml): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        if ($xml !== '') {
            $dom->loadXML($xml, LIBXML_NONET);
        }

        return $dom;
    }

    public function xml(DOMDocument $dom): string
    {
        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Unable to write presentation XML.');
        }

        return $xml;
    }

    private function contentTypesXml(): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->xmlStandalone = true;
        $root = $dom->appendChild($dom->createElementNS(self::CONTENT_TYPES_NS, 'Types'));

        foreach ($this->defaults as $extension => $type) {
            $element = $dom->createElementNS(self::CONTENT_TYPES_NS, 'Default');
            $element->setAttribute('Extension', $extension);
            $element->setAttribute('ContentType', $type);
            $root->appendChild($element);
        }

        foreach ($this->overrides as $part => $type) {
            $element = $dom->createElementNS(self::CONTENT_TYPES_NS, 'Override');
            $element->setAttribute('PartName', '/'.$part);
            $element->setAttribute('ContentType', $type);
            $root->appendChild($element);
        }

        return $this->xml($dom);
    }

    private function resolve(string $from, string $target): string
    {
        $target = rawurldecode($target);
        $base = str_starts_with($target, '/') ? [] : array_filter(explode('/', dirname($from)), fn ($segment) => $segment !== '' && $segment !== '.');
        $segments = array_values($base);

        foreach (explode('/', ltrim($target, '/')) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    private function relative(string $from, string $to): string
    {
        $fromSegments = $from === '' ? [] : explode('/', dirname($from));
        $fromSegments = array_values(array_filter($fromSegments, fn ($segment) => $segment !== '.'));
        $toSegments = explode('/', $to);

        while ($fromSegments !== [] && count($toSegments) > 1 && $fromSegments[0] === $toSegments[0]) {
            array_shift($fromSegments);
            array_shift($toSegments);
        }

        return str_repeat('../', count($fromSegments)).implode('/', $toSegments);
    }
}
