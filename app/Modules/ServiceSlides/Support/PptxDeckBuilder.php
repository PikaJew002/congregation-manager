<?php

namespace App\Modules\ServiceSlides\Support;

use DOMDocument;
use DOMElement;

/**
 * Merges PowerPoint files the way "Keep Source Formatting" does: each library file brings its slide master,
 * layouts, and theme, and masters that match one already in the deck are reused.
 */
class PptxDeckBuilder implements DeckBuilder
{
    private const RELATIONSHIP_TYPES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    private const P14 = 'http://schemas.microsoft.com/office/powerpoint/2010/main';

    private const PRESENTATION_ORDER = [
        'sldMasterIdLst', 'notesMasterIdLst', 'handoutMasterIdLst', 'sldIdLst', 'sldSz', 'notesSz', 'smartTags',
        'embeddedFontLst', 'custShowLst', 'photoAlbum', 'custDataLst', 'kinsoku', 'defaultTextStyle', 'modifyVerifier', 'extLst',
    ];

    private const FIRST_MASTER_ID = 2147483648;

    private PptxPackage $out;

    private DOMDocument $presentation;

    private string $presentationPart;

    /** @var list<array{id: string, type: string, uri: string, target: string, external: bool}> */
    private array $presentationRels = [];

    /** @var list<array{id: int, rel: string}> */
    private array $slides = [];

    private int $notes = 0;

    private int $hidden = 0;

    private int $nextMasterId = self::FIRST_MASTER_ID;

    /** @var array<string, string> */
    private array $copied = [];

    /** @var array<string, string> */
    private array $media = [];

    /** @var array<string, list<string|null>> */
    private array $masters = [];

    /** @var array<string, array<string, string>> */
    private array $sourceLayouts = [];

    /**
     * @param  list<array{type: string, index?: int, path?: string, label?: string, slides?: list<int>}>  $entries
     * @return list<string>
     */
    public function build(string $templatePath, array $entries, string $destination): array
    {
        $template = PptxPackage::open($templatePath);
        $this->start($templatePath, $template);
        $templateSlides = $template->slideParts();
        $templateSize = PptxInspector::formatSize($template->slideSize());
        $warnings = [];
        $sizeMismatches = [];

        foreach ($entries as $entry) {
            if ($entry['type'] === 'template_slide') {
                $slideIndex = $entry['index'] ?? 0;
                if (! isset($templateSlides[$slideIndex])) {
                    $warnings[] = "Template slide {$slideIndex} does not exist.";

                    continue;
                }
                $this->appendSlide($template, $templateSlides[$slideIndex], fn (string $layout) => $layout);

                continue;
            }

            if ($entry['type'] !== 'source' || ! isset($entry['path'])) {
                $warnings[] = 'Unknown deck entry.';

                continue;
            }

            $source = PptxPackage::open($entry['path']);
            $sourceSize = PptxInspector::formatSize($source->slideSize());
            if ($sourceSize !== $templateSize) {
                $sizeMismatches[$sourceSize ?? ''][] = $entry['label'] ?? basename($entry['path']);
            }

            foreach ($this->selectedSlides($source, $entry['slides'] ?? null) as $slide) {
                $this->appendSlide($source, $slide, fn (string $layout) => $this->layoutFor($source, $layout));
            }
        }

        $this->finish();
        $this->out->save($destination);

        return [...$warnings, ...$this->sizeWarnings($sizeMismatches, $templateSize)];
    }

    /**
     * @param  array<string, list<string>>  $mismatches
     * @return list<string>
     */
    private function sizeWarnings(array $mismatches, ?string $templateSize): array
    {
        $warnings = [];

        foreach ($mismatches as $sourceSize => $names) {
            $list = $this->nameList($names);
            $plural = count($names) > 1;
            $verb = $plural ? 'are' : 'is';
            $whose = $plural ? 'their' : 'its';

            if ($sourceSize !== '' && $templateSize !== null) {
                $warnings[] = "{$list} {$verb} {$sourceSize}, and the template is {$templateSize}, so {$whose} slides may not fit.";

                continue;
            }

            $warnings[] = "{$list} ".($plural ? 'have' : 'has')." a different slide size from the template, so {$whose} slides may not fit.";
        }

        return $warnings;
    }

    /**
     * @param  list<string>  $names
     */
    private function nameList(array $names): string
    {
        if (count($names) < 2) {
            return $names[0] ?? '';
        }

        $last = array_pop($names);

        return implode(', ', $names).(count($names) === 1 ? '' : ',').' and '.$last;
    }

    private function start(string $templatePath, PptxPackage $template): void
    {
        $this->out = PptxPackage::open($templatePath);
        $this->presentationPart = $this->out->presentationPart();
        $this->presentation = $this->out->dom($this->out->files[$this->presentationPart]);
        $this->presentationRels = array_values(array_filter(
            $this->out->rels($this->presentationPart),
            fn (array $rel) => $rel['type'] !== 'slide',
        ));
        $this->slides = [];
        $this->notes = 0;
        $this->hidden = 0;
        $this->copied = [];
        $this->media = [];
        $this->masters = [];
        $this->sourceLayouts = [];

        foreach (array_keys($this->out->files) as $name) {
            if (str_starts_with($name, 'ppt/slides/') || str_starts_with($name, 'ppt/notesSlides/')) {
                unset($this->out->files[$name]);
            } elseif (str_starts_with($name, 'ppt/media/')) {
                $this->media[$this->mediaHash($this->out, $name)] = $name;
            }
        }

        $list = $this->presentationChild('sldIdLst');
        while ($list->firstChild) {
            $list->removeChild($list->firstChild);
        }

        // Custom shows and sections list slides by id, and none of the template's ids survive.
        foreach ($this->presentation->getElementsByTagNameNS(PptxPackage::PRESENTATION_NS, 'custShowLst') as $shows) {
            $shows->parentNode->removeChild($shows);
        }
        foreach (iterator_to_array($this->presentation->getElementsByTagNameNS(self::P14, 'sectionLst')) as $sections) {
            $extension = $sections->parentNode;
            $extension->parentNode?->removeChild($extension);
        }

        $ids = [];
        foreach ($this->presentation->getElementsByTagNameNS(PptxPackage::PRESENTATION_NS, 'sldMasterId') as $master) {
            $ids[] = (int) $master->getAttribute('id');
        }

        foreach ($this->presentationRels as $rel) {
            if ($rel['type'] !== 'slideMaster') {
                continue;
            }
            $this->masters[$this->fingerprint($template, $rel['target'])] = $this->layoutsOf($template, $rel['target']);
            foreach ($this->out->dom($this->out->files[$rel['target']] ?? '')->getElementsByTagNameNS(PptxPackage::PRESENTATION_NS, 'sldLayoutId') as $layout) {
                $ids[] = (int) $layout->getAttribute('id');
            }
        }

        $this->nextMasterId = max([self::FIRST_MASTER_ID - 1, ...$ids]) + 1;
    }

    /**
     * @param  list<int>|null  $only
     * @return list<string>
     */
    private function selectedSlides(PptxPackage $package, ?array $only): array
    {
        $slides = $package->slideParts();

        if ($only !== null) {
            return array_values(array_filter($slides, fn (string $slide, int $index) => in_array($index, $only, true), ARRAY_FILTER_USE_BOTH));
        }

        while ($slides !== [] && PptxInspector::isBlank($package->files[end($slides)])) {
            array_pop($slides);
        }

        return $slides;
    }

    /**
     * @param  callable(string): string  $layoutFor
     */
    private function appendSlide(PptxPackage $source, string $slide, callable $layoutFor): void
    {
        $new = $this->out->freshName('ppt/slides/slide1.xml');
        $this->out->files[$new] = $source->files[$slide];
        $this->out->setContentType($new, $source->contentType($slide));
        $rels = [];

        foreach ($source->rels($slide) as $rel) {
            if ($rel['external']) {
                $rels[] = $rel;

                continue;
            }

            $target = match ($rel['type']) {
                'slideLayout' => $layoutFor($rel['target']),
                'notesSlide' => $this->copyNotes($source, $rel['target'], $new),
                // Links to other slides cannot follow them into the merged deck.
                'slide' => $new,
                'comments', 'commentAuthors' => null,
                default => $this->copyPart($source, $rel['target']),
            };

            if ($target !== null) {
                $rels[] = [...$rel, 'target' => $target];
            }
        }

        $this->out->writeRels($new, $rels);

        $id = $this->nextRelId();
        $this->presentationRels[] = ['id' => $id, 'type' => 'slide', 'uri' => self::RELATIONSHIP_TYPES.'slide', 'target' => $new, 'external' => false];
        $this->slides[] = ['id' => 256 + count($this->slides), 'rel' => $id];

        if (preg_match('/<p:sld\b[^>]*\sshow="(0|false)"/', $source->files[$slide])) {
            $this->hidden++;
        }
    }

    private function copyNotes(PptxPackage $source, string $notes, string $slide): ?string
    {
        if (! isset($source->files[$notes])) {
            return null;
        }

        $rels = [];
        foreach ($source->rels($notes) as $rel) {
            if ($rel['external']) {
                $rels[] = $rel;

                continue;
            }

            $target = match ($rel['type']) {
                'slide' => $slide,
                'notesMaster' => $this->notesMaster($source, $rel['target']),
                default => $this->copyPart($source, $rel['target']),
            };

            if ($target === null && $rel['type'] === 'notesMaster') {
                return null;
            }

            if ($target !== null) {
                $rels[] = [...$rel, 'target' => $target];
            }
        }

        $new = $this->out->freshName('ppt/notesSlides/notesSlide1.xml');
        $this->out->files[$new] = $source->files[$notes];
        $this->out->setContentType($new, $source->contentType($notes));
        $this->out->writeRels($new, $rels);
        $this->notes++;

        return $new;
    }

    private function notesMaster(PptxPackage $source, string $master): ?string
    {
        foreach ($this->presentationRels as $rel) {
            if ($rel['type'] === 'notesMaster' && isset($this->out->files[$rel['target']])) {
                return $rel['target'];
            }
        }

        $copied = $this->copyPart($source, $master);
        if ($copied === null) {
            return null;
        }

        $id = $this->nextRelId();
        $this->presentationRels[] = ['id' => $id, 'type' => 'notesMaster', 'uri' => self::RELATIONSHIP_TYPES.'notesMaster', 'target' => $copied, 'external' => false];
        $element = $this->presentation->createElementNS(PptxPackage::PRESENTATION_NS, 'p:notesMasterId');
        $element->setAttributeNS(PptxPackage::RELATIONSHIPS_NS, 'r:id', $id);
        $this->presentationChild('notesMasterIdLst')->appendChild($element);

        return $copied;
    }

    /**
     * Copies a part and everything it links to. Media is shared when the bytes match.
     */
    private function copyPart(PptxPackage $source, string $part): ?string
    {
        if (! isset($source->files[$part])) {
            return null;
        }

        $key = spl_object_id($source).'|'.$part;
        if (isset($this->copied[$key])) {
            return $this->copied[$key];
        }

        $isMedia = str_starts_with($part, 'ppt/media/');
        $hash = $isMedia ? $this->mediaHash($source, $part) : null;
        if ($hash !== null && isset($this->media[$hash])) {
            return $this->copied[$key] = $this->media[$hash];
        }

        $new = $this->out->freshName($part);
        $this->out->files[$new] = $source->files[$part];
        $this->out->setContentType($new, $source->contentType($part));
        $this->copied[$key] = $new;
        if ($hash !== null) {
            $this->media[$hash] = $new;
        }

        $rels = [];
        foreach ($source->rels($part) as $rel) {
            $target = $rel['external'] ? $rel['target'] : $this->copyPart($source, $rel['target']);
            if ($target !== null) {
                $rels[] = [...$rel, 'target' => $target];
            }
        }
        if ($rels !== []) {
            $this->out->writeRels($new, $rels);
        }

        return $new;
    }

    private function layoutFor(PptxPackage $source, string $layout): string
    {
        $master = null;
        foreach ($source->rels($layout) as $rel) {
            if ($rel['type'] === 'slideMaster') {
                $master = $rel['target'];
            }
        }

        $key = spl_object_id($source).'|'.$master;
        $map = $this->sourceLayouts[$key] ??= $master !== null ? $this->importMaster($source, $master) : [];

        return $map[$layout] ?? $this->copyLayout($source, $layout, null);
    }

    /**
     * @return array<string, string>
     */
    private function importMaster(PptxPackage $source, string $master): array
    {
        $fingerprint = $this->fingerprint($source, $master);
        $layouts = $this->layoutsOf($source, $master);
        $existing = $this->masters[$fingerprint] ?? null;

        if ($existing !== null && count($existing) === count($layouts) && ! in_array(null, $existing, true)) {
            return array_combine($layouts, $existing);
        }

        $new = $this->out->freshName('ppt/slideMasters/slideMaster1.xml');
        $this->out->files[$new] = '';
        $this->out->setContentType($new, $source->contentType($master));
        $map = [];
        $rels = [];

        foreach ($source->rels($master) as $rel) {
            if ($rel['external']) {
                $rels[] = $rel;

                continue;
            }

            $target = $rel['type'] === 'slideLayout'
                ? $map[$rel['target']] = $this->copyLayout($source, $rel['target'], $new)
                : $this->copyPart($source, $rel['target']);

            if ($target !== null) {
                $rels[] = [...$rel, 'target' => $target];
            }
        }

        $this->out->writeRels($new, $rels);

        $dom = $source->dom($source->files[$master]);
        foreach ($dom->getElementsByTagNameNS(PptxPackage::PRESENTATION_NS, 'sldLayoutId') as $layout) {
            $layout->setAttribute('id', (string) $this->nextMasterId++);
        }
        $this->out->files[$new] = $this->out->xml($dom);

        $id = $this->nextRelId();
        $this->presentationRels[] = ['id' => $id, 'type' => 'slideMaster', 'uri' => self::RELATIONSHIP_TYPES.'slideMaster', 'target' => $new, 'external' => false];
        $element = $this->presentation->createElementNS(PptxPackage::PRESENTATION_NS, 'p:sldMasterId');
        $element->setAttribute('id', (string) $this->nextMasterId++);
        $element->setAttributeNS(PptxPackage::RELATIONSHIPS_NS, 'r:id', $id);
        $this->presentationChild('sldMasterIdLst')->appendChild($element);

        $this->masters[$fingerprint] = array_map(fn (string $layout) => $map[$layout] ?? null, $layouts);

        return $map;
    }

    private function copyLayout(PptxPackage $source, string $layout, ?string $master): string
    {
        $key = spl_object_id($source).'|'.$layout;
        if (isset($this->copied[$key])) {
            return $this->copied[$key];
        }

        $new = $this->out->freshName('ppt/slideLayouts/slideLayout1.xml');
        $this->out->files[$new] = $source->files[$layout];
        $this->out->setContentType($new, $source->contentType($layout));
        $this->copied[$key] = $new;
        $rels = [];

        foreach ($source->rels($layout) as $rel) {
            $target = match (true) {
                $rel['external'] => $rel['target'],
                $rel['type'] === 'slideMaster' => $master,
                default => $this->copyPart($source, $rel['target']),
            };

            if ($target !== null) {
                $rels[] = [...$rel, 'target' => $target];
            }
        }

        $this->out->writeRels($new, $rels);

        return $new;
    }

    /**
     * @return list<string>
     */
    private function layoutsOf(PptxPackage $package, string $master): array
    {
        $targets = array_column($package->rels($master), 'target', 'id');
        $layouts = [];

        foreach ($package->dom($package->files[$master] ?? '')->getElementsByTagNameNS(PptxPackage::PRESENTATION_NS, 'sldLayoutId') as $layout) {
            $target = $targets[$layout->getAttributeNS(PptxPackage::RELATIONSHIPS_NS, 'id')] ?? null;
            if ($target !== null) {
                $layouts[] = $target;
            }
        }

        return $layouts;
    }

    /**
     * Identifies a master by its content, its layouts, and its theme. PowerPoint gives every saved copy
     * of a master new creation and layout ids, so those are left out.
     */
    private function fingerprint(PptxPackage $package, string $master): string
    {
        $parts = [];

        foreach ($package->rels($master) as $rel) {
            if ($rel['external']) {
                $parts[] = $rel['type'].' '.$rel['target'];

                continue;
            }

            $part = $rel['type'].' '.$this->contentHash($package, $rel['target']);
            if ($rel['type'] === 'slideLayout') {
                foreach ($package->rels($rel['target']) as $layoutRel) {
                    if ($layoutRel['type'] !== 'slideMaster') {
                        $part .= ' '.$layoutRel['type'].':'.($layoutRel['external'] ? $layoutRel['target'] : $this->contentHash($package, $layoutRel['target']));
                    }
                }
            }
            $parts[] = $part;
        }

        sort($parts);

        return hash('sha256', $this->contentHash($package, $master)."\n".implode("\n", $parts));
    }

    private function contentHash(PptxPackage $package, string $part): string
    {
        $contents = $package->files[$part] ?? '';

        if (str_ends_with($part, '.xml')) {
            $contents = preg_replace(
                ['/<p14:creationId\b[^>]*\/>/', '/\sr:(?:id|embed|link|pict)="[^"]*"/', '/(<p:sldLayoutId)\b[^>]*\/>/'],
                ['', '', '$1/>'],
                $contents,
            ) ?? $contents;
        }

        return hash('sha256', $contents);
    }

    private function mediaHash(PptxPackage $package, string $part): string
    {
        return hash('sha256', ($package->contentType($part) ?? '').'|'.$package->files[$part]);
    }

    private function nextRelId(): string
    {
        $highest = 0;
        foreach ($this->presentationRels as $rel) {
            if (preg_match('/^rId(\d+)$/', $rel['id'], $matches)) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return 'rId'.($highest + 1);
    }

    private function presentationChild(string $localName): DOMElement
    {
        $root = $this->presentation->documentElement;
        $later = array_slice(self::PRESENTATION_ORDER, array_search($localName, self::PRESENTATION_ORDER, true) + 1);
        $before = null;

        foreach ($root->childNodes as $child) {
            if (! $child instanceof DOMElement || $child->namespaceURI !== PptxPackage::PRESENTATION_NS) {
                continue;
            }
            if ($child->localName === $localName) {
                return $child;
            }
            if ($before === null && in_array($child->localName, $later, true)) {
                $before = $child;
            }
        }

        $element = $this->presentation->createElementNS(PptxPackage::PRESENTATION_NS, 'p:'.$localName);

        return $root->insertBefore($element, $before);
    }

    private function finish(): void
    {
        $list = $this->presentationChild('sldIdLst');
        foreach ($this->slides as $slide) {
            $element = $this->presentation->createElementNS(PptxPackage::PRESENTATION_NS, 'p:sldId');
            $element->setAttribute('id', (string) $slide['id']);
            $element->setAttributeNS(PptxPackage::RELATIONSHIPS_NS, 'r:id', $slide['rel']);
            $list->appendChild($element);
        }

        $this->out->files[$this->presentationPart] = $this->out->xml($this->presentation);
        $this->out->writeRels($this->presentationPart, $this->presentationRels);
        $this->updateAppProperties();
        $this->out->prune();
    }

    /**
     * Slide titles and counts in docProps/app.xml describe the template; the titles are optional, so they go.
     */
    private function updateAppProperties(): void
    {
        $part = null;
        foreach ($this->out->rels('') as $rel) {
            if ($rel['type'] === 'extended-properties') {
                $part = $rel['target'];
            }
        }

        if ($part === null || ! isset($this->out->files[$part])) {
            return;
        }

        $dom = $this->out->dom($this->out->files[$part]);
        $root = $dom->documentElement;
        $counts = ['Slides' => count($this->slides), 'Notes' => $this->notes, 'HiddenSlides' => $this->hidden];

        foreach (iterator_to_array($root->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if (in_array($child->localName, ['HeadingPairs', 'TitlesOfParts'], true)) {
                $root->removeChild($child);
            } elseif (isset($counts[$child->localName])) {
                $child->textContent = (string) $counts[$child->localName];
            }
        }

        $this->out->files[$part] = $this->out->xml($dom);
    }
}
