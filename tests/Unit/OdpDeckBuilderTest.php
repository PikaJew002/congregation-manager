<?php

namespace Tests\Unit;

use App\Modules\ServiceSlides\Support\OdpDeckBuilder;
use Tests\TestCase;
use ZipArchive;

class OdpDeckBuilderTest extends TestCase
{
    public function test_it_merges_template_slides_and_song_decks_and_drops_trailing_blanks(): void
    {
        $destination = storage_path('framework/testing/merged-music.odp');
        $warnings = (new OdpDeckBuilder)->build(
            $this->fixture('music-template.odp'),
            [
                ['type' => 'template_slide', 'index' => 0],
                ['type' => 'template_slide', 'index' => 1],
                ['type' => 'source', 'path' => $this->fixture('kumi-ori.odp')],
                ['type' => 'template_slide', 'index' => 1],
                ['type' => 'source', 'path' => $this->fixture('trees-of-the-field.odp')],
            ],
            $destination,
        );

        $this->assertSame([], $warnings);
        $slides = $this->slideTexts($destination);
        $this->assertCount(14, $slides);
        $this->assertStringContainsString('Welcome', $slides[0]);
        $this->assertStringContainsString('Kumi Ori', implode("\n", $slides));
        $this->assertStringContainsString('Trees of The Field', implode("\n", $slides));
        $this->assertSame(1, $this->uniqueImageCount($destination));
        $this->assertSettingsFollowPages($destination);
        $this->assertIdsAreUnique($destination);
        $this->assertReferencesResolve($destination);
    }

    public function test_it_slots_liturgy_items_around_a_title_slide(): void
    {
        $destination = storage_path('framework/testing/merged-liturgy.odp');
        $warnings = (new OdpDeckBuilder)->build(
            $this->fixture('liturgy-template.odp'),
            [
                ['type' => 'source', 'path' => $this->fixture('psalm-19.odp')],
                ['type' => 'source', 'path' => $this->fixture('shema.odp')],
                ['type' => 'template_slide', 'index' => 0],
                ['type' => 'source', 'path' => $this->fixture('amidah.odp')],
                ['type' => 'source', 'path' => $this->fixture('adon-olam.odp')],
            ],
            $destination,
        );

        $this->assertSame([], $warnings);
        $slides = $this->slideTexts($destination);
        $this->assertGreaterThanOrEqual(8, count($slides));
        $this->assertStringContainsString('Congregational Prayer', implode("\n", $slides));
        $this->assertStringContainsString('Psalm 19', $slides[0]);
        $this->assertIdsAreUnique($destination);
        $this->assertReferencesResolve($destination);
    }

    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/slides/'.$name);
    }

    /**
     * @return list<string>
     */
    private function slideTexts(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('content.xml');
        $zip->close();

        preg_match_all('/<draw:page\b.*?<\/draw:page>/s', $xml, $matches);

        return array_map(function (string $page) {
            $text = preg_replace('/<[^>]+>/', ' ', $page) ?? '';

            return trim(preg_replace('/\s+/', ' ', $text) ?? '');
        }, $matches[0]);
    }

    private function assertSettingsFollowPages(string $path): void
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('content.xml');
        $zip->close();

        $settings = strpos($xml, '<presentation:settings');
        $this->assertNotFalse($settings);
        $this->assertGreaterThan(strrpos($xml, '</draw:page>'), $settings);
    }

    private function assertIdsAreUnique(string $path): void
    {
        [$content, $styles] = $this->documents($path);
        preg_match_all('/\s(?:draw|xml):id="([^"]+)"/', $content.$styles, $matches);
        $ids = array_count_values($matches[1]);

        $this->assertSame([], array_keys(array_filter($ids, fn (int $count) => $count > 1)));
    }

    private function assertReferencesResolve(string $path): void
    {
        [$content, $styles] = $this->documents($path);
        $reference = '/\s\w+:((?:parent-)?style-name|text-style-name|master-page-name|presentation-page-layout-name|font-name|fill-image-name|fill-gradient-name|list-style-name|data-style-name)="([^"]+)"/';
        $defined = fn (string $xml) => preg_match_all('/\s(?:style|draw):name="([^"]+)"/', $xml, $names) ? $names[1] : [];

        foreach (['content.xml' => [$content, [...$defined($content), ...$defined($styles)]], 'styles.xml' => [$styles, $defined($styles)]] as $file => [$xml, $names]) {
            preg_match_all($reference, $xml, $matches, PREG_SET_ORDER);
            $missing = array_unique(array_map(
                fn (array $match) => "{$match[1]}={$match[2]}",
                array_filter($matches, fn (array $match) => ! in_array($match[2], $names, true)),
            ));

            $this->assertSame([], array_values($missing), "Dangling references in {$file}");
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function documents(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $documents = [$zip->getFromName('content.xml'), $zip->getFromName('styles.xml')];
        $zip->close();

        return $documents;
    }

    private function uniqueImageCount(string $path): int
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $hashes = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (str_starts_with($name, 'Pictures/') || str_starts_with($name, 'media/')) {
                $hashes[hash('sha256', $zip->getFromIndex($index))] = true;
            }
        }
        $zip->close();

        return count($hashes);
    }
}
