<?php

namespace Tests\Unit;

use App\Modules\ServiceSlides\Support\PptxDeckBuilder;
use App\Modules\ServiceSlides\Support\PptxInspector;
use App\Modules\ServiceSlides\Support\PptxPackage;
use Tests\TestCase;

class PptxDeckBuilderTest extends TestCase
{
    public function test_it_merges_template_slides_and_liturgy_files(): void
    {
        $destination = storage_path('framework/testing/merged-liturgy.pptx');
        $warnings = (new PptxDeckBuilder)->build(
            $this->fixture('liturgy-service.pptx'),
            [
                ['type' => 'template_slide', 'index' => 0],
                ['type' => 'source', 'path' => $this->fixture('barchu.pptx')],
                ['type' => 'source', 'path' => $this->fixture('eloheynu.pptx')],
                ['type' => 'source', 'path' => $this->fixture('modim.pptx')],
                ['type' => 'template_slide', 'index' => 41],
                ['type' => 'source', 'path' => $this->fixture('kaddish.pptx')],
                ['type' => 'source', 'path' => $this->fixture('adon-olam.pptx')],
                ['type' => 'template_slide', 'index' => 54],
            ],
            $destination,
        );

        $this->assertSame([], $warnings);
        $package = PptxPackage::open($destination);
        $slides = array_map(fn (string $part) => implode(' ', PptxInspector::lines($package->files[$part])), $package->slideParts());

        $this->assertCount(15, $slides);
        $this->assertStringStartsWith('Psalm 29', $slides[0]);
        $this->assertStringStartsWith('Barchu', $slides[1]);
        $this->assertStringStartsWith('Silent Meditation', $slides[2]);
        $this->assertStringStartsWith('Eloheynu', $slides[3]);
        $this->assertStringStartsWith('Modim', $slides[4]);
        $this->assertSame('', $slides[7]);
        $this->assertStringStartsWith('Mourner’s Kaddish', $slides[8]);
        $this->assertStringStartsWith('Adon Olam', $slides[11]);
        $this->assertSame('', $slides[14]);

        $this->assertPackageIsConsistent($package);
        $this->assertStringContainsString('<Slides>15</Slides>', $package->files['docProps/app.xml']);
    }

    public function test_items_built_from_the_same_theme_share_one_imported_master(): void
    {
        $destination = storage_path('framework/testing/merged-shared-master.pptx');
        (new PptxDeckBuilder)->build(
            $this->fixture('liturgy-service.pptx'),
            [
                ['type' => 'source', 'path' => $this->fixture('barchu.pptx')],
                ['type' => 'source', 'path' => $this->fixture('eloheynu.pptx')],
                ['type' => 'source', 'path' => $this->fixture('modim.pptx')],
                ['type' => 'source', 'path' => $this->fixture('adon-olam.pptx')],
            ],
            $destination,
        );

        $package = PptxPackage::open($destination);
        $template = PptxPackage::open($this->fixture('liturgy-service.pptx'));
        $masters = fn (PptxPackage $package) => array_values(array_filter(array_keys($package->files), fn (string $name) => preg_match('#^ppt/slideMasters/slideMaster\d+\.xml$#', $name)));

        $this->assertCount(count($masters($template)) + 1, $masters($package));
        $this->assertCount(1, array_unique(array_map(fn (string $slide) => $this->masterOf($package, $slide), $package->slideParts())));
        $this->assertPackageIsConsistent($package);
    }

    public function test_repeated_template_slides_and_images_are_not_duplicated(): void
    {
        $destination = storage_path('framework/testing/merged-repeat.pptx');
        (new PptxDeckBuilder)->build(
            $this->fixture('liturgy-service.pptx'),
            [
                ['type' => 'template_slide', 'index' => 47],
                ['type' => 'template_slide', 'index' => 54],
                ['type' => 'template_slide', 'index' => 47],
            ],
            $destination,
        );

        $package = PptxPackage::open($destination);
        $media = array_filter(array_keys($package->files), fn (string $name) => str_starts_with($name, 'ppt/media/'));
        $hashes = array_map(fn (string $name) => hash('sha256', $package->files[$name]), $media);

        $this->assertCount(3, $package->slideParts());
        $this->assertSame(count($hashes), count(array_unique($hashes)));
        $this->assertPackageIsConsistent($package);
    }

    public function test_a_different_slide_size_names_the_library_item_and_both_sizes(): void
    {
        $package = PptxPackage::open($this->fixture('barchu.pptx'));
        $part = $package->presentationPart();
        $package->files[$part] = str_replace(
            '<p:sldSz cx="12192000" cy="6858000"/>',
            '<p:sldSz cx="9144000" cy="6858000"/>',
            $package->files[$part],
        );
        $narrow = storage_path('framework/testing/barchu-4x3.pptx');
        $package->save($narrow);

        $destination = storage_path('framework/testing/merged-size.pptx');
        $warnings = (new PptxDeckBuilder)->build(
            $this->fixture('liturgy-service.pptx'),
            [
                ['type' => 'source', 'path' => $narrow, 'label' => 'Barchu'],
                ['type' => 'source', 'path' => $narrow, 'label' => 'Shema'],
            ],
            $destination,
        );

        $this->assertSame([
            'Barchu and Shema are 25.4cm x 19.05cm, and the template is 33.867cm x 19.05cm, so their slides may not fit.',
        ], $warnings);
    }

    public function test_missing_template_slides_are_reported(): void
    {
        $destination = storage_path('framework/testing/merged-missing.pptx');
        $warnings = (new PptxDeckBuilder)->build(
            $this->fixture('liturgy-service.pptx'),
            [['type' => 'template_slide', 'index' => 99], ['type' => 'source', 'path' => $this->fixture('barchu.pptx')]],
            $destination,
        );

        $this->assertSame(['Template slide 99 does not exist.'], $warnings);
        $this->assertCount(2, PptxPackage::open($destination)->slideParts());
    }

    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/slides/'.$name);
    }

    private function masterOf(PptxPackage $package, string $slide): ?string
    {
        foreach ($package->rels($slide) as $rel) {
            if ($rel['type'] === 'slideLayout') {
                foreach ($package->rels($rel['target']) as $layoutRel) {
                    if ($layoutRel['type'] === 'slideMaster') {
                        return $layoutRel['target'];
                    }
                }
            }
        }

        return null;
    }

    private function assertPackageIsConsistent(PptxPackage $package): void
    {
        $reachable = [];
        $queue = [''];
        while ($queue !== []) {
            $part = array_pop($queue);
            $ids = [];
            foreach ($package->rels($part) as $rel) {
                $ids[] = $rel['id'];
                if ($rel['external']) {
                    continue;
                }
                $this->assertArrayHasKey($rel['target'], $package->files, "{$part} links to missing {$rel['target']}");
                if (! isset($reachable[$rel['target']])) {
                    $reachable[$rel['target']] = true;
                    $queue[] = $rel['target'];
                }
            }

            if ($part !== '' && str_ends_with($part, '.xml')) {
                preg_match_all('/\sr:(?:id|embed|link|pict|dm|lo|qs|cs)="([^"]*)"/', $package->files[$part], $matches);
                foreach (array_unique($matches[1]) as $id) {
                    if ($id !== '') {
                        $this->assertContains($id, $ids, "{$part} uses {$id}, which has no relationship");
                    }
                }
            }
        }

        foreach (array_keys($package->files) as $name) {
            if ($name === '[Content_Types].xml' || str_ends_with($name, '.rels')) {
                continue;
            }
            $this->assertArrayHasKey($name, $reachable, "{$name} is not linked from anywhere");
            $this->assertNotNull($package->contentType($name), "{$name} has no content type");
        }

        foreach (array_keys($package->overrides) as $name) {
            $this->assertArrayHasKey($name, $package->files, "Content type override for missing {$name}");
        }

        $presentation = $package->files[$package->presentationPart()];
        preg_match_all('/<p:sldId id="(\d+)"/', $presentation, $slideIds);
        $this->assertSame(count($slideIds[1]), count(array_unique($slideIds[1])));

        $masterIds = preg_match_all('/<p:sldMasterId id="(\d+)"/', $presentation, $matches) ? $matches[1] : [];
        foreach (array_keys($package->files) as $name) {
            if (preg_match('#^ppt/slideMasters/slideMaster\d+\.xml$#', $name) && preg_match_all('/<p:sldLayoutId id="(\d+)"/', $package->files[$name], $layoutIds)) {
                array_push($masterIds, ...$layoutIds[1]);
            }
        }
        $this->assertSame(count($masterIds), count(array_unique($masterIds)), 'Master and layout ids must be unique');
        foreach ($masterIds as $id) {
            $this->assertGreaterThanOrEqual(2147483648, (int) $id);
        }
    }
}
