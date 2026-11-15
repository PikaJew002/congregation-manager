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
