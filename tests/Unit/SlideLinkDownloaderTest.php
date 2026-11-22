<?php

namespace Tests\Unit;

use App\Modules\ServiceSlides\Support\SlideLinkDownloader;
use InvalidArgumentException;
use Tests\TestCase;

class SlideLinkDownloaderTest extends TestCase
{
    public function test_it_rewrites_dropbox_share_links_for_direct_download(): void
    {
        [$provider, $url] = (new SlideLinkDownloader)->resolve(
            'https://www.dropbox.com/scl/fi/idzfjfnmmscr1lhpcew6z/As-the-Mountains.odp?rlkey=24a44kj17d948c5jh9u45k8cr&st=vmaet5wb&dl=0',
        );

        $this->assertSame('dropbox', $provider);
        $this->assertSame(
            'https://www.dropbox.com/scl/fi/idzfjfnmmscr1lhpcew6z/As-the-Mountains.odp?rlkey=24a44kj17d948c5jh9u45k8cr&dl=1',
            $url,
        );
    }

    public function test_it_rewrites_google_drive_edit_and_view_links(): void
    {
        $downloader = new SlideLinkDownloader;

        foreach ([
            'https://docs.google.com/presentation/d/1Ug67oy8xtZl-4sEj5a4Db6-PNw9tikmc/edit?usp=sharing&ouid=105382066458014470022&rtpof=true&sd=true',
            'https://drive.google.com/file/d/1aOlcIaIVhPZBiJoOiePH1ralnX-Xh1wo/view',
            'https://drive.google.com/open?id=1Ug67oy8xtZl-4sEj5a4Db6-PNw9tikmc',
            'https://drive.google.com/uc?export=download&id=1Ug67oy8xtZl-4sEj5a4Db6-PNw9tikmc',
        ] as $link) {
            [$provider, $url] = $downloader->resolve($link);
            $this->assertSame('drive', $provider);
            $this->assertMatchesRegularExpression('#^https://drive\.google\.com/uc\?export=download&id=[a-zA-Z0-9_-]+$#', $url);
        }

        $this->assertSame(
            'https://drive.google.com/uc?export=download&id=1Ug67oy8xtZl-4sEj5a4Db6-PNw9tikmc',
            $downloader->resolve('https://docs.google.com/presentation/d/1Ug67oy8xtZl-4sEj5a4Db6-PNw9tikmc/edit?usp=sharing')[1],
        );
        $this->assertSame(
            'https://drive.google.com/uc?export=download&id=1aOlcIaIVhPZBiJoOiePH1ralnX-Xh1wo',
            $downloader->resolve('https://drive.google.com/file/d/1aOlcIaIVhPZBiJoOiePH1ralnX-Xh1wo/view')[1],
        );
    }

    public function test_it_rejects_other_hosts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Send an https Dropbox or Google Drive link.');

        (new SlideLinkDownloader)->resolve('https://example.com/song.odp');
    }
}
