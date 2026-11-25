<?php

namespace Tests\Unit;

use App\Modules\ServiceSlides\Support\SongSlideFormatter;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\TestCase;
use ZipArchive;

class SongSlideFormatterTest extends TestCase
{
    /** Inner edges of the white border on the fixtures' 28 x 15.75 cm background. */
    private const BORDER = ['left' => 0.84, 'top' => 0.88, 'right' => 27.16, 'bottom' => 14.87];

    public function test_titles_span_the_border_right_aligned_and_lyrics_fill_it_centred(): void
    {
        foreach (['kumi-ori.odp', 'trees-of-the-field.odp'] as $fixture) {
            $destination = storage_path('framework/testing/formatted-'.$fixture);
            $notes = (new SongSlideFormatter)->format($this->fixture($fixture), $destination);
            $this->assertSame([], $notes, $fixture);

            [$xpath, $styles] = $this->open($destination);
            $pages = $xpath->query('//draw:page');

            $titles = $this->shapes($xpath, $pages->item(0));
            $this->assertGreaterThan(1, count($titles));
            foreach ($titles as $shape) {
                $this->assertEqualsWithDelta($this->box($titles[0])['x'], $this->box($shape)['x'], 0.001);
                $this->assertEqualsWithDelta($this->box($titles[0])['width'], $this->box($shape)['width'], 0.001);
                $this->assertInsideBorder($shape, $fixture);
                $this->assertAlignment($xpath, $styles, $shape, 'right');
            }

            for ($index = 1; $index < $pages->length; $index++) {
                foreach ($this->shapes($xpath, $pages->item($index)) as $shape) {
                    $box = $this->box($shape);
                    $this->assertEqualsWithDelta(14.0, $box['x'] + $box['width'] / 2, 0.05, "{$fixture} slide {$index} is not centred.");
                    $this->assertEqualsWithDelta(7.875, $box['y'] + $box['height'] / 2, 0.05, "{$fixture} slide {$index} is not centred.");
                    $this->assertGreaterThan(25, $box['width']);
                    $this->assertInsideBorder($shape, $fixture);
                    $this->assertAlignment($xpath, $styles, $shape, 'center');
                }
            }

            $this->assertSame([], $this->nonWhiteText($xpath, $styles), $fixture);
            $this->assertReferencesResolve($destination);
        }
    }

    public function test_lyrics_too_wide_for_the_border_are_shrunk(): void
    {
        $long = 'You shall go out with joy and be led forth with peace';
        $source = $this->edited('trees-of-the-field.odp', fn (string $content) => str_replace('You shall go out with joy', $long, $content));
        $destination = storage_path('framework/testing/formatted-long-line.odp');

        $notes = (new SongSlideFormatter)->format($source, $destination);

        $this->assertCount(1, $notes);
        $this->assertStringStartsWith('Slide 2: text shrunk to', $notes[0]);
        [$xpath, $styles] = $this->open($destination);
        $span = $xpath->query("//text:span[contains(., 'joy and be led')]")->item(0);
        $original = $this->open($source);
        $before = $original[0]->query("//text:span[contains(., 'joy and be led')]")->item(0);
        $this->assertLessThan(
            (float) $this->resolve($original[0], $original[1], $before, 'text-properties', 'fo:font-size'),
            (float) $this->resolve($xpath, $styles, $span, 'text-properties', 'fo:font-size'),
        );
    }

    public function test_backgrounds_without_a_white_border_keep_their_layout(): void
    {
        $source = $this->edited('kumi-ori.odp', styles: fn (string $styles) => str_replace('draw:fill="bitmap"', 'draw:fill="solid"', $styles));
        $destination = storage_path('framework/testing/formatted-no-border.odp');

        $notes = (new SongSlideFormatter)->format($source, $destination);

        $this->assertSame(['No white border was found on the slide background, so text was left where it is.'], $notes);
        $boxes = fn (string $path) => array_map(fn (DOMElement $shape) => $this->box($shape), $this->shapes($this->open($path)[0]));
        $this->assertEquals($boxes($source), $boxes($destination));
        [$xpath, $styles] = $this->open($destination);
        $this->assertSame([], $this->nonWhiteText($xpath, $styles));
    }

    public function test_title_and_author_lines_get_the_standard_fonts(): void
    {
        $expected = [
            'kumi-ori.odp' => ['Kumi Ori', 'Barry and Batya Segal'],
            'trees-of-the-field.odp' => ['Trees of The Field', 'Stuart Dauermann'],
            'blessed-be-your-name.odp' => ['Blessed Be Your Name', 'Matt Redman'],
            'come-thou-fount.odp' => ['Come, Thou Fount of Every Blessing', 'Robert Robinson, 1758'],
        ];

        foreach ($expected as $fixture => [$title, $author]) {
            $destination = storage_path('framework/testing/fonts-'.$fixture);
            $this->assertSame([], (new SongSlideFormatter)->format($this->fixture($fixture), $destination), $fixture);
            [$xpath, $styles] = $this->open($destination);

            $this->assertSame(['Liberation Sans', '30pt', 'bold', 'normal'], $this->font($xpath, $styles, $title), $fixture);
            $this->assertSame(['Liberation Mono', '15pt', 'normal', 'italic'], $this->font($xpath, $styles, $author), $fixture);
            $this->assertReferencesResolve($destination);
        }

        [$xpath, $styles] = $this->open(storage_path('framework/testing/fonts-kumi-ori.odp'));
        $this->assertSame('Waree', $this->font($xpath, $styles, 'קומי אורי')[0], 'The Hebrew title keeps its own font.');
    }

    /**
     * Family, size, weight and style of the first-slide span with this text.
     *
     * @return array{0: string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function font(DOMXPath $xpath, DOMXPath $styles, string $text): array
    {
        $span = $xpath->query("(//draw:page)[1]//text:span[normalize-space()='{$text}']")->item(0);
        $this->assertInstanceOf(DOMElement::class, $span, "No first-slide span reads \"{$text}\".");

        $face = $this->resolve($xpath, $styles, $span, 'text-properties', 'style:font-name');
        $family = $xpath->evaluate("string(//style:font-face[@style:name='{$face}']/@svg:font-family)")
            ?: $styles->evaluate("string(//style:font-face[@style:name='{$face}']/@svg:font-family)");

        return [
            trim($family, "'"),
            $this->resolve($xpath, $styles, $span, 'text-properties', 'fo:font-size'),
            $this->resolve($xpath, $styles, $span, 'text-properties', 'fo:font-weight'),
            $this->resolve($xpath, $styles, $span, 'text-properties', 'fo:font-style'),
        ];
    }

    /**
     * Blessed Be Your Name came from a PPTX, and PowerPoint showed none of its text: it sat in "ooxml-rect" custom shapes.
     */
    public function test_text_in_ooxml_rectangles_moves_into_text_frames(): void
    {
        $source = $this->fixture('blessed-be-your-name.odp');
        $destination = storage_path('framework/testing/formatted-blessed-be-your-name.odp');

        (new SongSlideFormatter)->format($source, $destination);

        [$before] = $this->open($source);
        [$xpath, $styles] = $this->open($destination);
        $this->assertGreaterThan(0, $before->query('//draw:page/draw:custom-shape[draw:enhanced-geometry/@draw:type="ooxml-rect"]')->length);
        $this->assertSame(0, $xpath->query('//draw:page/draw:custom-shape')->length);

        $text = fn (DOMXPath $document) => array_map(
            fn (DOMElement $paragraph) => $paragraph->textContent,
            iterator_to_array($document->query('//draw:page/*[not(self::presentation:notes)]//text:p')),
        );
        $this->assertSame($text($before), $text($xpath));

        foreach ($this->shapes($xpath) as $shape) {
            $this->assertSame('frame', $shape->localName);
            $this->assertSame(1, $xpath->query('draw:text-box', $shape)->length);
            $this->assertSame(0, $xpath->query('text:p', $shape)->length, 'Text must sit inside the text box.');
        }
        $this->assertSame([], $this->nonWhiteText($xpath, $styles));
        $this->assertReferencesResolve($destination);
    }

    /**
     * Come, Thou Fount came from a PPTX with a white master and the background on every slide. PowerPoint draws only the
     * master's background, so its white text was invisible.
     */
    public function test_slide_backgrounds_move_onto_their_master(): void
    {
        $destination = storage_path('framework/testing/formatted-come-thou-fount.odp');

        (new SongSlideFormatter)->format($this->fixture('come-thou-fount.odp'), $destination);

        [$xpath, $styles] = $this->open($destination);
        $this->assertSame([], $this->slideFills($xpath));
        $masters = array_unique(array_map(fn ($name) => $name->value, iterator_to_array($xpath->query('//draw:page/@draw:master-page-name'))));
        $this->assertCount(1, $masters, 'Slides sharing one picture should share one master.');
        $this->assertSame('bitmap', $this->masterFill($styles, reset($masters))['draw:fill']);
        $this->assertFillImageIsPackaged($destination, $this->masterFill($styles, reset($masters))['draw:fill-image-name']);
        $this->assertReferencesResolve($destination);
    }

    public function test_slides_with_different_backgrounds_get_their_own_master(): void
    {
        $source = $this->edited('come-thou-fount.odp', fn (string $content) => preg_replace(
            '/(<style:style style:name="i8_dp4" style:family="drawing-page"><style:drawing-page-properties [^>]*?)draw:fill="bitmap" draw:fill-image-name="[^"]*"/',
            '$1draw:fill="solid" draw:fill-color="#000000"',
            $content,
            1,
        ));
        $destination = storage_path('framework/testing/formatted-two-backgrounds.odp');

        (new SongSlideFormatter)->format($source, $destination);

        [$xpath, $styles] = $this->open($destination);
        $this->assertSame([], $this->slideFills($xpath));
        $pages = $xpath->query('//draw:page');
        $solid = $pages->item(2)->getAttribute('draw:master-page-name');
        $this->assertSame('#000000', $this->masterFill($styles, $solid)['draw:fill-color']);
        foreach ($pages as $index => $page) {
            if ($index !== 2) {
                $this->assertNotSame($solid, $page->getAttribute('draw:master-page-name'));
                $this->assertSame('bitmap', $this->masterFill($styles, $page->getAttribute('draw:master-page-name'))['draw:fill']);
            }
        }
        $this->assertReferencesResolve($destination);
    }

    /**
     * Fill settings left on the slides' own drawing-page styles.
     *
     * @return list<string>
     */
    private function slideFills(DOMXPath $xpath): array
    {
        $fills = [];
        foreach ($xpath->query('//draw:page/@draw:style-name') as $name) {
            $fill = $xpath->evaluate("string(//style:style[@style:name='{$name->value}']/style:drawing-page-properties/@draw:fill)");
            if ($fill !== '' && $fill !== 'none') {
                $fills[] = $name->value.': '.$fill;
            }
        }

        return $fills;
    }

    /**
     * @return array<string, string>
     */
    private function masterFill(DOMXPath $styles, string $master): array
    {
        $style = $styles->evaluate("string(//style:master-page[@style:name='{$master}']/@draw:style-name)");
        $properties = $styles->query("//style:style[@style:name='{$style}']/style:drawing-page-properties")->item(0);
        $this->assertInstanceOf(DOMElement::class, $properties, "Master {$master} has no drawing-page style.");

        $fill = [];
        foreach ($properties->attributes as $attribute) {
            $fill[$attribute->nodeName] = $attribute->value;
        }

        return $fill;
    }

    private function assertFillImageIsPackaged(string $path, string $name): void
    {
        [, $styles] = $this->open($path);
        $styles->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
        $href = $styles->evaluate("string(//draw:fill-image[@draw:name='{$name}']/@xlink:href)");
        $this->assertNotSame('', $href, "Fill image {$name} is not defined in styles.xml.");

        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertNotFalse($zip->getFromName($href), "{$href} is missing from the package.");
        $zip->close();
    }

    private function assertInsideBorder(DOMElement $shape, string $fixture): void
    {
        $box = $this->box($shape);
        $clearance = 0.2;

        $this->assertTrue(
            $box['x'] >= self::BORDER['left'] + $clearance
                && $box['y'] >= self::BORDER['top'] + $clearance
                && self::BORDER['right'] - $clearance >= $box['x'] + $box['width']
                && self::BORDER['bottom'] - $clearance >= $box['y'] + $box['height'],
            "A text box in {$fixture} comes within {$clearance} cm of the border: ".json_encode($box),
        );
    }

    private function assertAlignment(DOMXPath $xpath, DOMXPath $styles, DOMElement $shape, string $expected): void
    {
        foreach ($xpath->query('.//text:p', $shape) as $paragraph) {
            $this->assertSame($expected, $this->resolve($xpath, $styles, $paragraph, 'paragraph-properties', 'fo:text-align'));
        }
    }

    /**
     * Text on slides, ignoring speaker notes, whose effective colour is not white.
     *
     * @return list<string>
     */
    private function nonWhiteText(DOMXPath $xpath, DOMXPath $styles): array
    {
        $found = [];
        foreach ($xpath->query('//draw:page/*[not(self::presentation:notes)]//text:span[normalize-space()]') as $span) {
            $color = $this->resolve($xpath, $styles, $span, 'text-properties', 'fo:color') ?? 'automatic';
            if ($color !== '#ffffff') {
                $found[] = $color.' '.$span->textContent;
            }
        }

        return $found;
    }

    /**
     * Looks a property up through the styles of an element, its enclosing paragraphs and its shape, and their parents.
     */
    private function resolve(DOMXPath $xpath, DOMXPath $styles, DOMElement $element, string $group, string $attribute): ?string
    {
        $names = [];
        for ($node = $element; $node instanceof DOMElement && $node->localName !== 'page'; $node = $node->parentNode) {
            foreach (['text:style-name', 'draw:text-style-name', 'draw:style-name', 'presentation:style-name'] as $name) {
                $names[] = $node->getAttribute($name);
            }
        }

        foreach (array_filter($names) as $name) {
            for ($depth = 0; $name !== '' && $depth < 10; $depth++) {
                $style = $xpath->query("//style:style[@style:name='{$name}']")->item(0)
                    ?? $styles->query("//style:style[@style:name='{$name}']")->item(0);
                if (! $style instanceof DOMElement) {
                    break;
                }
                $value = $xpath->evaluate("string(style:{$group}/@{$attribute})", $style);
                if ($value !== '') {
                    return $value;
                }
                $name = $style->getAttribute('style:parent-style-name');
            }
        }

        return null;
    }

    private function assertReferencesResolve(string $path): void
    {
        [$xpath, $styles] = $this->open($path);
        $defined = [];
        foreach ([$xpath, $styles] as $document) {
            foreach ($document->query('//style:style/@style:name') as $name) {
                $defined[$name->value] = true;
            }
        }

        foreach ($xpath->query('//@text:style-name | //@draw:style-name | //@draw:text-style-name | //@presentation:style-name | //@style:parent-style-name') as $reference) {
            $this->assertArrayHasKey($reference->value, $defined, "{$reference->nodeName}={$reference->value} has no style.");
        }
    }

    /**
     * @return list<DOMElement>
     */
    private function shapes(DOMXPath $xpath, ?DOMElement $page = null): array
    {
        $query = '*[(self::draw:frame or self::draw:custom-shape) and normalize-space()]';

        return iterator_to_array($page ? $xpath->query($query, $page) : $xpath->query('//draw:page/'.$query));
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function box(DOMElement $shape): array
    {
        $centimeters = fn (string $value) => (float) $value * (str_ends_with($value, 'in') ? 2.54 : 1);

        return [
            'x' => $centimeters($shape->getAttribute('svg:x')),
            'y' => $centimeters($shape->getAttribute('svg:y')),
            'width' => $centimeters($shape->getAttribute('svg:width')),
            'height' => $centimeters($shape->getAttribute('svg:height')),
        ];
    }

    /**
     * @return array{0: DOMXPath, 1: DOMXPath} content and styles
     */
    private function open(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $documents = [];
        foreach (['content.xml', 'styles.xml'] as $name) {
            $dom = new DOMDocument;
            $dom->loadXML($zip->getFromName($name));
            $xpath = new DOMXPath($dom);
            foreach ([
                'draw' => 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0',
                'svg' => 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0',
                'style' => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
                'fo' => 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0',
                'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
                'presentation' => 'urn:oasis:names:tc:opendocument:xmlns:presentation:1.0',
            ] as $prefix => $namespace) {
                $xpath->registerNamespace($prefix, $namespace);
            }
            $documents[] = $xpath;
        }
        $zip->close();

        return $documents;
    }

    private function edited(string $fixture, ?callable $content = null, ?callable $styles = null): string
    {
        $path = storage_path('framework/testing/edited-'.$fixture);
        copy($this->fixture($fixture), $path);
        $zip = new ZipArchive;
        $zip->open($path);
        foreach (['content.xml' => $content, 'styles.xml' => $styles] as $name => $edit) {
            if ($edit) {
                $zip->addFromString($name, $edit($zip->getFromName($name)));
            }
        }
        $zip->close();

        return $path;
    }

    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/slides/'.$name);
    }
}
