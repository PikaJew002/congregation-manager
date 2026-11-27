<?php

namespace Tests\Unit;

use App\Support\LocalFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LocalFileTest extends TestCase
{
    public function test_it_reads_and_opens_a_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'local-file-');
        file_put_contents($path, 'Shalom');

        try {
            $this->assertSame('Shalom', LocalFile::read($path));

            $stream = LocalFile::open($path);
            $this->assertSame('Shalom', stream_get_contents($stream));
            fclose($stream);
        } finally {
            unlink($path);
        }
    }

    public function test_a_missing_file_fails_with_its_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read /nonexistent/deck.odp.');

        LocalFile::read('/nonexistent/deck.odp');
    }

    public function test_a_file_that_cannot_be_opened_fails_with_its_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to open /nonexistent/source.mp4.');

        LocalFile::open('/nonexistent/source.mp4', 'w');
    }
}
