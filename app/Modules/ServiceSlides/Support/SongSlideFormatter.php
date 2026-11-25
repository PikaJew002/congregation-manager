<?php

namespace App\Modules\ServiceSlides\Support;

use DOMDocument;
use DOMElement;
use DOMText;
use GdImage;

/**
 * Lays a song's text out inside the white border of its background. The first slide's title boxes all span the
 * border with right-aligned text, every other slide's lyrics are centred within it, and text is shrunk when it
 * would not fit. Text is also made explicitly white, as PowerPoint draws LibreOffice's automatic colour as black.
 * Two more PowerPoint gaps are closed: text in LibreOffice's "ooxml-rect" shapes is moved into text frames, and each
 * slide's own background is moved onto its master, the only background PowerPoint draws.
 */
class SongSlideFormatter
{
    use OdpDocument;

    /** Space between the border's inner edge and each text box, in centimetres. */
    private const GAP = 0.25;

    /** Lyrics are shrunk this far to avoid wrapping a line before wrapping is allowed. */
    private const NO_WRAP_SCALE = 0.75;

    private const MIN_SCALE = 0.5;

    /**
     * Arial advance widths in thousandths of an em for ASCII 32 to 126. Liberation Sans, the songs' usual font, shares
     * Arial's metrics; other characters count as OTHER_WIDTH and monospaced fonts as MONO_WIDTH.
     */
    private const ARIAL_WIDTHS = [
        'regular' => '278 278 355 556 556 889 667 191 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 '
            .'278 278 584 584 584 556 1015 667 667 722 722 667 611 778 722 278 500 667 556 833 722 778 667 778 722 667 611 '
            .'722 667 944 667 667 611 278 278 278 469 556 333 556 556 500 556 556 278 556 556 222 222 500 222 833 556 556 '
            .'556 556 333 500 278 556 500 722 500 500 500 334 260 334 584',
        'bold' => '278 333 474 556 556 889 722 238 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 '
            .'333 333 584 584 584 611 975 722 722 722 722 667 611 778 722 278 556 722 611 833 722 778 667 778 722 667 611 '
            .'722 667 944 667 667 611 333 278 333 584 556 333 556 611 556 611 556 333 611 611 278 278 556 278 889 611 611 '
            .'611 611 389 556 333 611 556 778 556 556 500 389 280 389 584',
    ];

    private const OTHER_WIDTH = 0.6;

    private const MONO_WIDTH = 0.6;

    /** Room for kerning and font substitution differences between LibreOffice and PowerPoint. */
    private const WIDTH_SAFETY = 1.1;

    private const LINE_HEIGHT = 1.2;

    private const POINT = 2.54 / 72;

    private DOMDocument $content;

    private DOMDocument $styles;

    private DOMElement $automaticStyles;

    /** @var array<string, list<DOMElement>> */
    private array $styleIndex;

    /** @var array<string, string> */
    private array $clones;

    /** @var array<string, list<float>> */
    private array $widths = [];

    /**
     * @return list<string> slides whose text was shrunk or may still not fit
     */
    public function format(string $path, string $destination): array
    {
        $files = $this->readZip($path);
        $this->content = $this->dom($files['content.xml']);
        $this->styles = $this->dom($files['styles.xml']);
        $this->automaticStyles = $this->section($this->content, 'automatic-styles');
        $this->styleIndex = $this->styleIndex($this->content, $this->styles);
        $this->clones = [];
        $pages = $this->pages($this->content);
        $this->frameRectangles($pages);
        $this->moveBackgroundsToMasters($pages, $files);
        if (isset($pages[0])) {
            $this->standardizeTitleFonts($this->textShapes($pages[0]));
        }
        $size = $this->pageSize($this->styles);
        $area = $size !== null ? $this->textArea($files, $size) : null;
        $notes = [];

        if ($area !== null) {
            foreach ($pages as $index => $page) {
                $shapes = $this->textShapes($page);
                if ($shapes === []) {
                    continue;
                }

                [$scale, $fits] = $index === 0 ? $this->layoutTitles($shapes, $area) : $this->layoutLyrics($shapes, $area);
                $slide = 'Slide '.($index + 1);
                if (! $fits) {
                    $notes[] = "{$slide}: text may not fit inside the border.";
                } elseif ($scale < 1) {
                    $notes[] = "{$slide}: text shrunk to ".round($scale * 100).'% to fit inside the border.';
                }
            }
        } else {
            $notes[] = 'No white border was found on the slide background, so text was left where it is.';
        }

        $this->whitenText($pages);
        $files['content.xml'] = $this->content->saveXML();
        $files['styles.xml'] = $this->styles->saveXML();
        $this->writeZip($destination, $files);

        return $notes;
    }

    /**
     * PowerPoint drops the text of the "ooxml-rect" custom shapes that LibreOffice's PPTX import leaves behind, so
     * those holding text become text frames with the same position and style.
     *
     * @param  list<DOMElement>  $pages
     */
    private function frameRectangles(array $pages): void
    {
        foreach ($pages as $page) {
            foreach (iterator_to_array($page->childNodes) as $shape) {
                if (! $shape instanceof DOMElement || $shape->namespaceURI !== self::DRAW || $shape->localName !== 'custom-shape') {
                    continue;
                }
                $geometry = $this->child($shape, self::DRAW, 'enhanced-geometry');
                if ($geometry?->getAttributeNS(self::DRAW, 'type') !== 'ooxml-rect' || trim($shape->textContent) === '') {
                    continue;
                }

                $frame = $this->content->createElementNS(self::DRAW, 'draw:frame');
                foreach (iterator_to_array($shape->attributes) as $attribute) {
                    $frame->setAttributeNS($attribute->namespaceURI, $attribute->nodeName, $attribute->value);
                }
                $textBox = $frame->appendChild($this->content->createElementNS(self::DRAW, 'draw:text-box'));
                foreach (iterator_to_array($shape->childNodes) as $child) {
                    if ($child === $geometry) {
                        continue;
                    }
                    ($child instanceof DOMElement && $child->namespaceURI === self::TEXT ? $textBox : $frame)->appendChild($child);
                }
                $shape->parentNode->replaceChild($frame, $shape);
            }
        }
    }

    /**
     * PowerPoint ignores a slide's own background and draws its master's, so slide backgrounds move to the master.
     * Slides that share a master but not a background each get a copy of the master with their background. Fill images
     * are compared by their bytes, as PPTX imports give every slide its own name for the same picture.
     *
     * @param  list<DOMElement>  $pages
     * @param  array<string, string>  $files
     */
    private function moveBackgroundsToMasters(array $pages, array $files): void
    {
        $imageKeys = [];
        foreach ([$this->styles, $this->content] as $dom) {
            foreach ($dom->getElementsByTagNameNS(self::DRAW, 'fill-image') as $image) {
                $href = $image->getAttributeNS(self::XLINK, 'href');
                $imageKeys[$image->getAttributeNS(self::DRAW, 'name')] ??= isset($files[$href]) ? hash('sha256', $files[$href]) : $href;
            }
        }

        $masters = [];
        foreach ($this->styles->getElementsByTagNameNS(self::STYLE, 'master-page') as $master) {
            $masters[$master->getAttributeNS(self::STYLE, 'name')] = $master;
        }

        $pagesPerMaster = [];
        $variants = [];
        $pageStyles = [];
        foreach ($pages as $page) {
            $masterName = $page->getAttributeNS(self::DRAW, 'master-page-name');
            $pagesPerMaster[$masterName] = ($pagesPerMaster[$masterName] ?? 0) + 1;

            $style = $this->namedStyle($this->content, $page->getAttributeNS(self::DRAW, 'style-name'));
            $properties = $style ? $this->child($style, self::STYLE, 'drawing-page-properties') : null;
            $background = $properties ? $this->backgroundAttributes($properties) : [];
            if (! isset($masters[$masterName]) || ($background['draw:fill'][1] ?? 'none') === 'none') {
                continue;
            }

            ksort($background);
            $comparable = array_map(fn (array $attribute) => $attribute[1], $background);
            if (isset($comparable['draw:fill-image-name'])) {
                $comparable['draw:fill-image-name'] = $imageKeys[$comparable['draw:fill-image-name']] ?? $comparable['draw:fill-image-name'];
            }
            $key = json_encode($comparable);
            $variants[$masterName][$key]['background'] = $background;
            $variants[$masterName][$key]['pages'][] = $page;
            $pageStyles[] = $properties;
        }

        foreach ($variants as $masterName => $backgrounds) {
            $master = $masters[$masterName];
            $wholeMaster = count($backgrounds) === 1 && count(reset($backgrounds)['pages']) === $pagesPerMaster[$masterName];

            foreach (array_values($backgrounds) as $index => $variant) {
                $target = $wholeMaster ? $master : $this->copyMaster($master, $masters, $masterName.'-background'.($index + 1));
                $this->setMasterBackground($target, $variant['background']);
                foreach ($variant['pages'] as $page) {
                    $page->setAttributeNS(self::DRAW, 'draw:master-page-name', $target->getAttributeNS(self::STYLE, 'name'));
                }
            }
        }

        foreach ($pageStyles as $properties) {
            foreach ($this->backgroundAttributes($properties) as [$namespace, $value, $local]) {
                $properties->removeAttributeNS($namespace, $local);
            }
        }
    }

    /**
     * The fill settings on a drawing-page style, keyed by qualified name.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> namespace, value and local name
     */
    private function backgroundAttributes(DOMElement $properties): array
    {
        $background = [];
        foreach (iterator_to_array($properties->attributes) as $attribute) {
            $isBackground = match ($attribute->namespaceURI) {
                self::DRAW => str_starts_with($attribute->localName, 'fill')
                    || in_array($attribute->localName, ['background-size', 'opacity', 'opacity-name', 'gradient-step-count', 'tile-repeat-offset'], true),
                self::STYLE => $attribute->localName === 'repeat',
                default => false,
            };
            if ($isBackground) {
                $background[$attribute->nodeName] = [$attribute->namespaceURI, $attribute->value, $attribute->localName];
            }
        }

        return $background;
    }

    /**
     * @param  array<string, DOMElement>  $masters
     */
    private function copyMaster(DOMElement $master, array &$masters, string $base): DOMElement
    {
        $name = $base;
        for ($suffix = 2; isset($masters[$name]); $suffix++) {
            $name = $base.'-'.$suffix;
        }

        $copy = $master->cloneNode(true);
        $copy->setAttributeNS(self::STYLE, 'style:name', $name);
        $copy->removeAttributeNS(self::STYLE, 'display-name');
        $master->parentNode->insertBefore($copy, $master->nextSibling);
        $masters[$name] = $copy;

        return $copy;
    }

    /**
     * Points the master at a copy of its drawing-page style that carries the background, bringing over any fill
     * image or gradient that only content.xml defined.
     *
     * @param  array<string, array{0: string, 1: string, 2: string}>  $background
     */
    private function setMasterBackground(DOMElement $master, array $background): void
    {
        $oldName = $master->getAttributeNS(self::DRAW, 'style-name');
        $old = $oldName !== '' ? $this->namedStyle($this->styles, $oldName) : null;
        if ($old) {
            $style = $old->cloneNode(true);
        } else {
            $style = $this->styles->createElementNS(self::STYLE, 'style:style');
            $style->setAttributeNS(self::STYLE, 'style:family', 'drawing-page');
        }

        $base = ($oldName !== '' ? $oldName : 'Mdp').'-background';
        $name = $base;
        for ($suffix = 2; isset($this->styleIndex[$name]); $suffix++) {
            $name = $base.'-'.$suffix;
        }
        $style->setAttributeNS(self::STYLE, 'style:name', $name);

        $properties = $this->child($style, self::STYLE, 'drawing-page-properties')
            ?? $style->appendChild($this->styles->createElementNS(self::STYLE, 'style:drawing-page-properties'));
        foreach ($this->backgroundAttributes($properties) as [$namespace, $value, $local]) {
            $properties->removeAttributeNS($namespace, $local);
        }
        foreach ($background as $qualified => [$namespace, $value]) {
            $properties->setAttributeNS($namespace, $qualified, $value);
            if (in_array($qualified, ['draw:fill-image-name', 'draw:fill-gradient-name', 'draw:fill-hatch-name', 'draw:opacity-name'], true)) {
                $this->shareFillDefinition($value);
            }
        }

        $this->section($this->styles, 'automatic-styles')->appendChild($style);
        $this->styleIndex[$name] = [$style];
        $master->setAttributeNS(self::DRAW, 'draw:style-name', $name);
    }

    private function shareFillDefinition(string $name): void
    {
        $find = function (DOMDocument $dom) use ($name): ?DOMElement {
            foreach ($dom->getElementsByTagNameNS(self::DRAW, '*') as $element) {
                if (in_array($element->localName, ['fill-image', 'gradient', 'hatch', 'opacity'], true)
                    && $element->getAttributeNS(self::DRAW, 'name') === $name) {
                    return $element;
                }
            }

            return null;
        };

        if (! $find($this->styles) && ($definition = $find($this->content))) {
            $this->section($this->styles, 'styles')->appendChild($this->styles->importNode($definition, true));
        }
    }

    /**
     * Gives the first slide's title and author lines the standard fonts. The author is the lowest text box and the
     * title the highest one above it without Hebrew; a Hebrew title or a translation line keeps its own font. Titles
     * still shrink later when they would not fit inside the border.
     *
     * @param  list<DOMElement>  $shapes
     */
    private function standardizeTitleFonts(array $shapes): void
    {
        $top = fn (DOMElement $shape) => $this->toCentimeters($shape->getAttributeNS(self::SVG, 'y')) ?? 0.0;
        usort($shapes, fn (DOMElement $a, DOMElement $b) => $top($a) <=> $top($b));

        $author = count($shapes) > 1 ? array_pop($shapes) : null;
        $title = null;
        foreach ($shapes as $shape) {
            if (! preg_match('/\p{Hebrew}/u', $shape->textContent)) {
                $title = $shape;
                break;
            }
        }

        if ($title) {
            $this->setFont($title, 'title-font', 'Liberation Sans', 'swiss', 'variable', 30, 'bold', 'normal');
        }
        if ($author) {
            $this->setFont($author, 'author-font', 'Liberation Mono', 'modern', 'fixed', 15, 'normal', 'italic');
        }
    }

    private function setFont(DOMElement $shape, string $tag, string $family, string $generic, string $pitch, float $size, string $weight, string $style): void
    {
        $properties = ['text-properties' => [
            'style:font-name' => $this->fontFace($family, $generic, $pitch),
            'fo:font-family' => null,
            'style:font-family-generic' => null,
            'style:font-pitch' => null,
            'style:font-charset' => null,
            'style:font-style-name' => null,
            'fo:font-size' => $size.'pt',
            'style:font-size-asian' => $size.'pt',
            'style:font-size-complex' => $size.'pt',
            'fo:font-weight' => $weight,
            'style:font-weight-asian' => $weight,
            'style:font-weight-complex' => $weight,
            'fo:font-style' => $style,
            'style:font-style-asian' => $style,
            'style:font-style-complex' => $style,
        ]];

        foreach ($this->paragraphs($shape) as $paragraph) {
            $this->restyle($paragraph, self::TEXT, 'text:style-name', 'paragraph', $tag, $properties, $shape->getAttributeNS(self::DRAW, 'text-style-name'));
        }
        foreach (iterator_to_array($shape->getElementsByTagNameNS(self::TEXT, 'span')) as $span) {
            $this->restyle($span, self::TEXT, 'text:style-name', 'text', $tag, $properties);
        }
    }

    /**
     * The name of content.xml's font face for the family, declaring one when there is none.
     */
    private function fontFace(string $family, string $generic, string $pitch): string
    {
        $declarations = $this->section($this->content, 'font-face-decls');
        $names = [];
        foreach ($declarations->getElementsByTagNameNS(self::STYLE, 'font-face') as $face) {
            $name = $face->getAttributeNS(self::STYLE, 'name');
            if (trim($face->getAttributeNS(self::SVG, 'font-family'), "'\"") === $family) {
                return $name;
            }
            $names[$name] = true;
        }

        $name = $family;
        for ($suffix = 2; isset($names[$name]); $suffix++) {
            $name = $family.$suffix;
        }
        $face = $declarations->appendChild($this->content->createElementNS(self::STYLE, 'style:font-face'));
        $face->setAttributeNS(self::STYLE, 'style:name', $name);
        $face->setAttributeNS(self::SVG, 'svg:font-family', str_contains($family, ' ') ? "'{$family}'" : $family);
        $face->setAttributeNS(self::STYLE, 'style:font-family-generic', $generic);
        $face->setAttributeNS(self::STYLE, 'style:font-pitch', $pitch);

        return $name;
    }

    /**
     * @param  list<DOMElement>  $shapes
     * @param  array{left: float, top: float, right: float, bottom: float}  $area
     * @return array{0: float, 1: bool}
     */
    private function layoutTitles(array $shapes, array $area): array
    {
        foreach ($shapes as $shape) {
            $this->place($shape, $area['left'], $area['right'] - $area['left']);
            $this->restyleShape($shape, 'title', [
                'draw:auto-grow-width' => 'false',
                'fo:wrap-option' => 'wrap',
                'draw:textarea-horizontal-align' => 'right',
                'fo:min-width' => null,
                'fo:max-width' => null,
            ]);
            $this->alignParagraphs($shape, 'right');
        }

        $fits = $this->moveInside($shapes, $area, center: false);

        return $this->fitAll($shapes, wrap: false, fits: $fits);
    }

    /**
     * @param  list<DOMElement>  $shapes
     * @param  array{left: float, top: float, right: float, bottom: float}  $area
     * @return array{0: float, 1: bool}
     */
    private function layoutLyrics(array $shapes, array $area): array
    {
        $single = count($shapes) === 1;

        foreach ($shapes as $shape) {
            $this->place(
                $shape,
                $area['left'],
                $area['right'] - $area['left'],
                $single ? $area['top'] : null,
                $single ? $area['bottom'] - $area['top'] : null,
            );
            $this->restyleShape($shape, $single ? 'lyrics' : 'lyric-stack', [
                'draw:auto-grow-width' => 'false',
                'fo:wrap-option' => 'wrap',
                'draw:textarea-horizontal-align' => 'center',
                'fo:min-width' => null,
                'fo:max-width' => null,
                ...$single ? [
                    'draw:auto-grow-height' => 'false',
                    'draw:textarea-vertical-align' => 'middle',
                    'fo:min-height' => null,
                    'fo:max-height' => null,
                ] : [],
            ]);
            $this->alignParagraphs($shape, 'center');
        }

        $fits = $this->moveInside($shapes, $area, center: true);

        return $this->fitAll($shapes, wrap: true, fits: $fits);
    }

    /**
     * @param  list<DOMElement>  $shapes
     * @return array{0: float, 1: bool}
     */
    private function fitAll(array $shapes, bool $wrap, bool $fits): array
    {
        $smallest = 1.0;
        foreach ($shapes as $shape) {
            [$scale, $shapeFits] = $this->fit($shape, $wrap);
            $smallest = min($smallest, $scale);
            $fits = $fits && $shapeFits;
        }

        return [$smallest, $fits];
    }

    /**
     * The border's inner edges on the slide background, inset by GAP, in centimetres.
     *
     * @param  array<string, string>  $files
     * @param  array{width: float, height: float}  $size
     * @return array{left: float, top: float, right: float, bottom: float}|null
     */
    private function textArea(array $files, array $size): ?array
    {
        $image = $this->backgroundImage($files);
        $border = $image !== null ? $this->detectBorder($image) : null;
        if ($border === null) {
            return null;
        }

        return [
            'left' => $border['left'] * $size['width'] + self::GAP,
            'top' => $border['top'] * $size['height'] + self::GAP,
            'right' => $border['right'] * $size['width'] - self::GAP,
            'bottom' => $border['bottom'] * $size['height'] - self::GAP,
        ];
    }

    /**
     * @param  array<string, string>  $files
     */
    private function backgroundImage(array $files): ?string
    {
        $page = $this->pages($this->content)[0] ?? null;
        if (! $page) {
            return null;
        }

        $masterName = $page->getAttributeNS(self::DRAW, 'master-page-name');
        $master = null;
        foreach ($this->styles->getElementsByTagNameNS(self::STYLE, 'master-page') as $candidate) {
            if ($candidate->getAttributeNS(self::STYLE, 'name') === $masterName) {
                $master = $candidate;
            }
        }

        $candidates = [
            $this->namedStyle($this->content, $page->getAttributeNS(self::DRAW, 'style-name')),
            $master ? $this->namedStyle($this->styles, $master->getAttributeNS(self::DRAW, 'style-name')) : null,
        ];

        foreach ($candidates as $style) {
            $properties = $style ? $this->child($style, self::STYLE, 'drawing-page-properties') : null;
            if (! $properties || $properties->getAttributeNS(self::DRAW, 'fill') !== 'bitmap') {
                continue;
            }

            $imageName = $properties->getAttributeNS(self::DRAW, 'fill-image-name');
            foreach ([$this->styles, $this->content] as $dom) {
                foreach ($dom->getElementsByTagNameNS(self::DRAW, 'fill-image') as $fillImage) {
                    if ($fillImage->getAttributeNS(self::DRAW, 'name') === $imageName) {
                        return $files[$fillImage->getAttributeNS(self::XLINK, 'href')] ?? null;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Finds the thin bright lines across the middle of the background, as fractions of the image.
     *
     * @return array{left: float, top: float, right: float, bottom: float}|null
     */
    private function detectBorder(string $bytes): ?array
    {
        if (! function_exists('imagecreatefromstring') || ! ($image = @imagecreatefromstring($bytes))) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $middleX = intdiv($width, 2);
        $middleY = intdiv($height, 2);
        $threshold = $this->brightness($image, $middleX, $middleY) + 80;
        $row = fn (int $x) => $this->brightness($image, $x, $middleY) > $threshold;
        $column = fn (int $y) => $this->brightness($image, $middleX, $y) > $threshold;

        $left = $this->lineEdge($row, 0, intdiv($width, 5));
        $right = $this->lineEdge($row, $width - 1, $width - 1 - intdiv($width, 5));
        $top = $this->lineEdge($column, 0, intdiv($height, 5));
        $bottom = $this->lineEdge($column, $height - 1, $height - 1 - intdiv($height, 5));

        if ($left === null || $right === null || $top === null || $bottom === null) {
            return null;
        }

        return [
            'left' => $left / $width,
            'top' => $top / $height,
            'right' => ($right + 1) / $width,
            'bottom' => ($bottom + 1) / $height,
        ];
    }

    private function brightness(GdImage $image, int $x, int $y): float
    {
        $color = imagecolorat($image, $x, $y);

        return ((($color >> 16) & 255) + (($color >> 8) & 255) + ($color & 255)) / 3;
    }

    /**
     * Walks inward from the slide edge and returns the first pixel past a bright line, skipping any bright fringe at the edge itself.
     */
    private function lineEdge(callable $bright, int $from, int $to): ?int
    {
        $step = $from < $to ? 1 : -1;
        $seenDark = false;
        $inLine = false;

        for ($position = $from; $position !== $to; $position += $step) {
            if ($bright($position)) {
                $inLine = $seenDark;
            } elseif ($inLine) {
                return $position;
            } else {
                $seenDark = true;
            }
        }

        return null;
    }

    /**
     * @return list<DOMElement>
     */
    private function textShapes(DOMElement $page): array
    {
        $shapes = [];
        foreach ($page->childNodes as $shape) {
            if (! $shape instanceof DOMElement || ! in_array($shape->localName, ['frame', 'custom-shape'], true)) {
                continue;
            }
            $hasText = $shape->localName === 'custom-shape' || $this->child($shape, self::DRAW, 'text-box');
            if ($hasText && trim($shape->textContent) !== '' && $shape->hasAttributeNS(self::SVG, 'x')) {
                $shapes[] = $shape;
            }
        }

        return $shapes;
    }

    private function place(DOMElement $shape, ?float $x, ?float $width, ?float $y = null, ?float $height = null): void
    {
        foreach (['x' => $x, 'width' => $width, 'y' => $y, 'height' => $height] as $attribute => $value) {
            if ($value !== null) {
                $shape->setAttributeNS(self::SVG, 'svg:'.$attribute, round($value, 3).'cm');
            }
        }
    }

    /**
     * Moves the shapes together so they sit inside the area, centring them vertically when asked.
     *
     * @param  list<DOMElement>  $shapes
     * @param  array{left: float, top: float, right: float, bottom: float}  $area
     */
    private function moveInside(array $shapes, array $area, bool $center): bool
    {
        $top = INF;
        $bottom = -INF;
        foreach ($shapes as $shape) {
            $y = $this->toCentimeters($shape->getAttributeNS(self::SVG, 'y')) ?? 0.0;
            $top = min($top, $y);
            $bottom = max($bottom, $y + ($this->toCentimeters($shape->getAttributeNS(self::SVG, 'height')) ?? 0.0));
        }

        $shift = $center ? ($area['top'] + $area['bottom'] - $top - $bottom) / 2 : 0.0;
        if ($bottom + $shift > $area['bottom']) {
            $shift = $area['bottom'] - $bottom;
        }
        if ($top + $shift < $area['top']) {
            $shift = $area['top'] - $top;
        }

        if (abs($shift) > 0.001) {
            foreach ($shapes as $shape) {
                $y = $this->toCentimeters($shape->getAttributeNS(self::SVG, 'y')) ?? 0.0;
                $this->place($shape, null, null, $y + $shift);
            }
        }

        return $bottom - $top <= $area['bottom'] - $area['top'] + 0.001;
    }

    /**
     * Shrinks the shape's text until it fits. Titles must stay on one line per paragraph. Lyrics must fit the box height,
     * shrinking as far as NO_WRAP_SCALE to avoid wrapping a line, and otherwise wrapping at the largest size that fits.
     *
     * @return array{0: float, 1: bool}
     */
    private function fit(DOMElement $shape, bool $wrap): array
    {
        $lines = $this->measureLines($shape);
        [$width, $height] = $this->innerSize($shape);

        $fits = function (float $scale, bool $allowWrap) use ($lines, $width, $height, $wrap): bool {
            $total = 0.0;
            foreach ($lines as $line) {
                $rows = max(1, (int) ceil($line['width'] * $scale / $width));
                if ($rows > 1 && ! $allowWrap) {
                    return false;
                }
                $total += $rows * $line['height'] * $scale;
            }

            return ! $wrap || $total <= $height;
        };

        $largest = function (float $smallest, bool $allowWrap) use ($fits): ?float {
            for ($scale = 1.0; $scale >= $smallest - 0.001; $scale = round($scale - 0.05, 2)) {
                if ($fits($scale, $allowWrap)) {
                    return $scale;
                }
            }

            return null;
        };

        $scale = $largest(self::NO_WRAP_SCALE, false)
            ?? ($wrap ? $largest(self::MIN_SCALE, true) : $largest(self::MIN_SCALE, false))
            ?? self::MIN_SCALE;

        if ($scale < 1) {
            $this->scaleText($shape, $scale);
        }

        return [$scale, $fits($scale, $wrap)];
    }

    /**
     * Estimated width and height, in centimetres, of each hard line of text in the shape.
     *
     * @return list<array{width: float, height: float}>
     */
    private function measureLines(DOMElement $shape): array
    {
        $lines = [];
        foreach ($this->paragraphs($shape) as $paragraph) {
            $line = ['width' => 0.0, 'height' => 0.0];
            $this->measure($paragraph, $shape, $line, $lines);
            if ($line['height'] === 0.0) {
                $line['height'] = $this->fontSize($paragraph, $shape) * self::LINE_HEIGHT * self::POINT;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param  array{width: float, height: float}  $line
     * @param  list<array{width: float, height: float}>  $lines
     */
    private function measure(DOMElement $element, DOMElement $shape, array &$line, array &$lines): void
    {
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMText) {
                $this->addRun($line, $node->data, $element, $shape);
            } elseif (! $node instanceof DOMElement || $node->namespaceURI !== self::TEXT) {
                continue;
            } elseif ($node->localName === 'line-break') {
                $lines[] = $line;
                $line = ['width' => 0.0, 'height' => 0.0];
            } elseif ($node->localName === 's') {
                $this->addRun($line, str_repeat(' ', max(1, (int) $node->getAttributeNS(self::TEXT, 'c'))), $element, $shape);
            } elseif ($node->localName === 'tab') {
                $this->addRun($line, '    ', $element, $shape);
            } else {
                $this->measure($node, $shape, $line, $lines);
            }
        }
    }

    /**
     * @param  array{width: float, height: float}  $line
     */
    private function addRun(array &$line, string $text, DOMElement $context, DOMElement $shape): void
    {
        $names = $this->contextStyles($context, $shape);
        $size = $this->fontSize($context, $shape);
        $weight = $this->property($names, 'text-properties', self::FO, 'font-weight') ?? 'normal';
        $font = $this->property($names, 'text-properties', self::STYLE, 'font-name') ?? '';
        $bold = $weight === 'bold' || (is_numeric($weight) && (int) $weight >= 600);

        $ems = 0.0;
        foreach (mb_str_split($text) as $character) {
            $ems += match (true) {
                (bool) preg_match('/mono|courier/i', $font) => self::MONO_WIDTH,
                strlen($character) === 1 && ord($character) >= 32 && ord($character) <= 126 => $this->arialWidths($bold)[ord($character) - 32],
                default => self::OTHER_WIDTH,
            };
        }

        $line['width'] += $ems * self::WIDTH_SAFETY * $size * self::POINT;
        $line['height'] = max($line['height'], $size * self::LINE_HEIGHT * self::POINT);
    }

    /**
     * @return list<float>
     */
    private function arialWidths(bool $bold): array
    {
        $weight = $bold ? 'bold' : 'regular';

        return $this->widths[$weight] ??= array_map(fn (string $width) => (int) $width / 1000, explode(' ', self::ARIAL_WIDTHS[$weight]));
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function innerSize(DOMElement $shape): array
    {
        $names = $this->contextStyles($shape, $shape);
        $padding = fn (string $side, float $default) => $this->toCentimeters(
            $this->property($names, 'graphic-properties', self::FO, 'padding-'.$side)
                ?? $this->property($names, 'graphic-properties', self::FO, 'padding')
                ?? ''
        ) ?? $default;

        $width = ($this->toCentimeters($shape->getAttributeNS(self::SVG, 'width')) ?? 0.0) - $padding('left', 0.25) - $padding('right', 0.25);
        $height = ($this->toCentimeters($shape->getAttributeNS(self::SVG, 'height')) ?? 0.0) - $padding('top', 0.125) - $padding('bottom', 0.125);

        return [max($width, 0.1), max($height, 0.1)];
    }

    private function scaleText(DOMElement $shape, float $scale): void
    {
        $sizes = [];
        foreach ([...$this->paragraphs($shape), ...iterator_to_array($shape->getElementsByTagNameNS(self::TEXT, 'span'))] as $element) {
            $sizes[] = [$element, round($this->fontSize($element, $shape) * $scale, 1)];
        }

        foreach ($sizes as [$element, $size]) {
            $fallback = $element->localName === 'span' ? '' : $shape->getAttributeNS(self::DRAW, 'text-style-name');
            $this->restyle($element, self::TEXT, 'text:style-name', $element->localName === 'span' ? 'text' : 'paragraph', 'size'.$size, [
                'text-properties' => [
                    'fo:font-size' => $size.'pt',
                    'style:font-size-asian' => $size.'pt',
                    'style:font-size-complex' => $size.'pt',
                ],
            ], $fallback);
        }
    }

    private function alignParagraphs(DOMElement $shape, string $align): void
    {
        foreach ($this->paragraphs($shape) as $paragraph) {
            $this->restyle($paragraph, self::TEXT, 'text:style-name', 'paragraph', $align, [
                'paragraph-properties' => ['fo:text-align' => $align],
            ], $shape->getAttributeNS(self::DRAW, 'text-style-name'));
        }
    }

    /**
     * @param  array<string, string|null>  $properties
     */
    private function restyleShape(DOMElement $shape, string $tag, array $properties): void
    {
        $presentation = $shape->hasAttributeNS(self::PRESENTATION, 'style-name');
        $this->restyle(
            $shape,
            $presentation ? self::PRESENTATION : self::DRAW,
            $presentation ? 'presentation:style-name' : 'draw:style-name',
            $presentation ? 'presentation' : 'graphic',
            $tag,
            ['graphic-properties' => $properties],
        );
    }

    /**
     * Points the element at a copy of its style with the given properties set, or removed when null. Copies are shared
     * between elements that had the same style, and a missing automatic style is replaced by one inheriting from it.
     *
     * @param  array<string, array<string, string|null>>  $properties
     */
    private function restyle(DOMElement $element, string $namespace, string $attribute, string $family, string $tag, array $properties, string $fallback = ''): void
    {
        $name = $element->getAttributeNS($namespace, substr($attribute, strpos($attribute, ':') + 1)) ?: $fallback;
        $key = $family."\0".$name."\0".$tag;

        if (! isset($this->clones[$key])) {
            $source = $this->automaticStyle($name, $family);
            if ($source) {
                $style = $source->cloneNode(true);
            } else {
                $style = $this->content->createElementNS(self::STYLE, 'style:style');
                $style->setAttributeNS(self::STYLE, 'style:family', $family);
                if ($name !== '') {
                    $style->setAttributeNS(self::STYLE, 'style:parent-style-name', $name);
                }
            }

            $base = ($name !== '' ? $name : $family).'-'.$tag;
            $newName = $base;
            for ($suffix = 2; isset($this->styleIndex[$newName]); $suffix++) {
                $newName = $base.'-'.$suffix;
            }
            $style->setAttributeNS(self::STYLE, 'style:name', $newName);

            foreach ($properties as $group => $values) {
                $target = $this->propertiesElement($style, $group);
                foreach ($values as $qualified => $value) {
                    [$prefix, $local] = explode(':', $qualified);
                    $propertyNamespace = ['draw' => self::DRAW, 'fo' => self::FO, 'style' => self::STYLE][$prefix];
                    if ($value === null) {
                        $target->removeAttributeNS($propertyNamespace, $local);
                    } else {
                        $target->setAttributeNS($propertyNamespace, $qualified, $value);
                    }
                }
            }

            $this->automaticStyles->appendChild($style);
            $this->styleIndex[$newName] = [$style];
            $this->clones[$key] = $newName;
        }

        $element->setAttributeNS($namespace, $attribute, $this->clones[$key]);
    }

    /**
     * Returns the style's properties element, adding it in the order ODF requires.
     */
    private function propertiesElement(DOMElement $style, string $group): DOMElement
    {
        $existing = $this->child($style, self::STYLE, $group);
        if ($existing) {
            return $existing;
        }

        $order = ['graphic-properties', 'paragraph-properties', 'text-properties'];
        $element = $this->content->createElementNS(self::STYLE, 'style:'.$group);
        foreach ($style->childNodes as $child) {
            if ($child instanceof DOMElement && array_search($child->localName, $order, true) > array_search($group, $order, true)) {
                return $style->insertBefore($element, $child);
            }
        }

        return $style->appendChild($element);
    }

    private function automaticStyle(string $name, string $family): ?DOMElement
    {
        if ($name === '') {
            return null;
        }

        foreach ($this->automaticStyles->childNodes as $style) {
            if ($style instanceof DOMElement && $style->localName === 'style'
                && $style->getAttributeNS(self::STYLE, 'name') === $name
                && $style->getAttributeNS(self::STYLE, 'family') === $family) {
                return $style;
            }
        }

        return null;
    }

    private function namedStyle(DOMDocument $dom, string $name): ?DOMElement
    {
        foreach ($dom->getElementsByTagNameNS(self::STYLE, 'style') as $style) {
            if ($style->getAttributeNS(self::STYLE, 'name') === $name) {
                return $style;
            }
        }

        return null;
    }

    /**
     * @return list<DOMElement>
     */
    private function paragraphs(DOMElement $shape): array
    {
        return array_values(array_filter(
            iterator_to_array($shape->getElementsByTagNameNS(self::TEXT, '*')),
            fn (DOMElement $element) => in_array($element->localName, ['p', 'h'], true),
        ));
    }

    private function fontSize(DOMElement $context, DOMElement $shape): float
    {
        $value = $this->property($this->contextStyles($context, $shape), 'text-properties', self::FO, 'font-size');
        $centimeters = $value !== null ? $this->toCentimeters($value) : null;

        return $centimeters !== null ? $centimeters / self::POINT : 18.0;
    }

    /**
     * Styles that apply to an element inside a shape, nearest first.
     *
     * @return list<string>
     */
    private function contextStyles(DOMElement $element, DOMElement $shape): array
    {
        $names = [];
        for ($node = $element; $node instanceof DOMElement && $node !== $shape; $node = $node->parentNode) {
            $names[] = $node->getAttributeNS(self::TEXT, 'style-name');
        }
        $names[] = $shape->getAttributeNS(self::DRAW, 'text-style-name');
        $names[] = $shape->getAttributeNS(self::DRAW, 'style-name');
        $names[] = $shape->getAttributeNS(self::PRESENTATION, 'style-name');

        return array_values(array_filter($names));
    }

    /**
     * @param  list<string>  $names
     */
    private function property(array $names, string $group, string $namespace, string $attribute): ?string
    {
        foreach ($names as $name) {
            for ($depth = 0; $name !== '' && $depth < 10; $depth++) {
                $style = $this->styleIndex[$name][0] ?? null;
                if (! $style) {
                    break;
                }
                $value = $this->child($style, self::STYLE, $group)?->getAttributeNS($namespace, $attribute);
                if ($value !== null && $value !== '' && ! str_ends_with($value, '%')) {
                    return $value;
                }
                $name = $style->getAttributeNS(self::STYLE, 'parent-style-name');
            }
        }

        foreach ($this->styles->getElementsByTagNameNS(self::STYLE, 'default-style') as $default) {
            if ($default->getAttributeNS(self::STYLE, 'family') === 'graphic') {
                $value = $this->child($default, self::STYLE, $group)?->getAttributeNS($namespace, $attribute);

                return $value !== null && $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param  list<DOMElement>  $pages
     */
    private function whitenText(array $pages): void
    {
        $pending = [];
        foreach ($pages as $page) {
            foreach ($this->slideElements($page) as $element) {
                foreach (iterator_to_array($element->attributes) as $attribute) {
                    if (in_array($attribute->localName, ['style-name', 'text-style-name'], true)) {
                        $pending[] = $attribute->value;
                    }
                }
            }
        }

        $done = [];
        while ($pending !== []) {
            $name = array_pop($pending);
            if (isset($done[$name])) {
                continue;
            }
            $done[$name] = true;

            foreach ($this->styleIndex[$name] ?? [] as $style) {
                if (! in_array($style->getAttributeNS(self::STYLE, 'family'), ['graphic', 'presentation', 'paragraph', 'text'], true)) {
                    continue;
                }
                $properties = $this->child($style, self::STYLE, 'text-properties')
                    ?? $style->appendChild($style->ownerDocument->createElementNS(self::STYLE, 'style:text-properties'));
                $properties->setAttributeNS(self::FO, 'fo:color', '#ffffff');
                $properties->removeAttributeNS(self::STYLE, 'use-window-font-color');

                $parent = $style->getAttributeNS(self::STYLE, 'parent-style-name');
                if ($parent !== '') {
                    $pending[] = $parent;
                }
            }
        }
    }
}
